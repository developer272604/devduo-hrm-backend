<?php

namespace App\Domain\Intelligence;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Insights\ActionCenter;
use App\Domain\Intelligence\Models\Finding;
use App\Domain\Intelligence\Models\FindingEvent;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persisted, deduplicated, explainable findings. NO external AI: findings are the deterministic outputs of the registered InsightProviders,
 * each already limited to what the audience user may see (permission + data scope). A finding row belongs to its audience user, so a
 * viewer can never read another viewer's scope through the Center.
 */
final class FindingService
{
    public const STATUSES = ['open', 'acknowledged', 'snoozed', 'resolved', 'dismissed'];

    /** Allowed manual transitions. Re-detection by a scan may additionally reopen resolved/dismissed findings (see scan()). */
    public const TRANSITIONS = [
        'open' => ['acknowledged', 'snoozed', 'resolved', 'dismissed'],
        'acknowledged' => ['open', 'snoozed', 'resolved', 'dismissed'],
        'snoozed' => ['open', 'acknowledged', 'resolved', 'dismissed'],
        'resolved' => ['open'],
        'dismissed' => ['open'],
    ];

    private const RANK = ['info' => 0, 'warning' => 1, 'action_required' => 2, 'critical' => 3];

    private const DUE_DAYS = ['critical' => 2, 'action_required' => 5, 'warning' => 14];

    public function __construct(private ActionCenter $center, private TaskService $tasks) {}

    public static function fingerprint(int $tenantId, int $audienceUserId, string $insightKey): string
    {
        return hash('sha256', 'tenant:'.$tenantId.'|audience:'.$audienceUserId.'|insight:'.$insightKey);
    }

    /** Re-runs every provider for the audience and reconciles stored findings. Idempotent: a second call with unchanged data only touches last_seen_at. */
    public function scan(User $audience): array
    {
        $summary = ['detected' => 0, 'created' => 0, 'updated' => 0, 'reopened' => 0, 'auto_resolved' => 0, 'unsnoozed' => 0];
        $now = now();
        $seen = [];
        foreach ($this->center->for($audience) as $insight) {
            $fp = self::fingerprint($audience->tenant_id, $audience->id, $insight['key']);
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;
            $summary['detected']++;
            $attrs = ['domain' => $insight['domain'], 'insight_key' => $insight['key'], 'severity' => $insight['severity'], 'title' => mb_substr((string) $insight['title'], 0, 300), 'description' => $insight['description'] ?? null,
                'count' => (int) $insight['count'], 'evidence' => ['actions' => array_values($insight['actions']), 'sample' => array_values($insight['sample'] ?? [])], 'explain' => $this->explain($audience, $insight), 'last_seen_at' => $now];
            $finding = Finding::where('fingerprint', $fp)->first();
            if (! $finding) {
                $days = self::DUE_DAYS[$insight['severity']] ?? null;
                $finding = Finding::create($attrs + ['fingerprint' => $fp, 'audience_user_id' => $audience->id, 'owner_user_id' => $audience->id, 'status' => 'open', 'first_seen_at' => $now,
                    'due_on' => $days ? now(TenantClock::timezone())->addDays($days)->toDateString() : null]);
                $this->event($finding, 'detected', null, 'open', 'First detected.');
                $summary['created']++;

                continue;
            }
            $escalated = (self::RANK[$insight['severity']] ?? 0) > (self::RANK[$finding->severity] ?? 0);
            $changed = $finding->count !== $attrs['count'] || $finding->severity !== $attrs['severity'];
            $finding->fill($attrs);
            if ($finding->status === 'resolved' || ($finding->status === 'dismissed' && $escalated)) {
                $from = $finding->status;
                $finding->fill(['status' => 'open', 'status_reason' => null, 'resolved_at' => null, 'snoozed_until' => null, 'occurrences' => $finding->occurrences + 1]);
                $this->event($finding, 'reopened', $from, 'open', $from === 'resolved' ? 'Detected again after being resolved.' : 'Severity escalated after dismissal.');
                $summary['reopened']++;
            } elseif ($finding->status === 'snoozed' && $finding->snoozed_until && $finding->snoozed_until->lte($now)) {
                $finding->fill(['status' => 'open', 'snoozed_until' => null]);
                $this->event($finding, 'unsnoozed', 'snoozed', 'open', 'Snooze period ended.');
                $summary['unsnoozed']++;
            } elseif ($changed) {
                $this->event($finding, 'updated', $finding->status, $finding->status, 'Count or severity changed.', ['count' => $attrs['count'], 'severity' => $attrs['severity']]);
                $summary['updated']++;
            }
            $finding->save();
        }
        // Stored active findings no longer produced by any provider are resolved automatically (the condition cleared).
        foreach (Finding::where('audience_user_id', $audience->id)->whereIn('status', ['open', 'acknowledged', 'snoozed'])->get() as $stale) {
            if (! isset($seen[$stale->fingerprint])) {
                $from = $stale->status;
                $stale->update(['status' => 'resolved', 'status_reason' => 'No longer detected.', 'resolved_at' => $now]);
                $this->event($stale, 'auto_resolved', $from, 'resolved', 'No longer detected by the source module.');
                $this->tasks->completeBySource('intelligence', 'finding:'.$stale->uuid);
                $summary['auto_resolved']++;
            }
        }
        Audit::record('intelligence.scan', 'intelligence', 'user:'.$audience->id, $summary);

        return $summary;
    }

    /** Explain-this payload stored with the finding: inputs, rule, threshold, as-of, data scope applied and what was withheld. */
    private function explain(User $audience, array $insight): array
    {
        $sample = count($insight['sample'] ?? []);
        $count = (int) $insight['count'];

        return [
            'inputs' => ['insight_key' => $insight['key'], 'matching_records' => $count, 'sample_shown' => $sample] + (is_array($insight['inputs'] ?? null) ? ['provider_inputs' => $insight['inputs']] : []),
            'rule' => $insight['rule'] ?? 'Raised by the "'.$insight['domain'].'" module provider when at least one record matches its condition.',
            'threshold' => $insight['threshold'] ?? 'count >= 1',
            'as_of' => ['generated_at' => now()->toIso8601String(), 'tenant_date' => TenantClock::today(), 'timezone' => TenantClock::timezone()],
            'scope' => ['data_scopes' => app(DataScope::class)->scopesFor($audience), 'applied_by' => 'Each module provider limits its results by the audience user permission, data scope and field rules before the finding is stored.'],
            'suppressed' => ['records_beyond_sample' => max(0, $count - $sample), 'note' => $count > $sample ? 'Individual names beyond the sample are withheld here; open the source link for the full list you are permitted to see.' : 'Nothing withheld.',
                'engine_rules' => 'Insights without a valid action link are dropped by the Action Center and never stored.'],
            'deterministic' => true,
        ];
    }

    public function find(User $viewer, string $uuid): Finding
    {
        return Finding::where('uuid', $uuid)->where('audience_user_id', $viewer->id)->firstOrFail();
    }

    public function list(User $viewer, array $f): array
    {
        $status = $f['status'] ?? 'active';
        $q = Finding::where('audience_user_id', $viewer->id)
            ->when($status === 'active', fn ($q) => $q->whereIn('status', ['open', 'acknowledged', 'snoozed']))
            ->when($status !== 'active' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($f['domain'] ?? null, fn ($q, $d) => $q->where('domain', $d))
            ->when($f['severity'] ?? null, fn ($q, $s) => $q->where('severity', $s));
        $rows = $q->orderByDesc('last_seen_at')->limit(300)->get()->sortBy(fn ($r) => [-(self::RANK[$r->severity] ?? 0), -$r->count])->values();

        return ['count' => $rows->count(), 'items' => $rows->map(fn ($r) => $this->present($r))->all(),
            'limitations' => 'Findings are deterministic outputs of module providers, stored per viewer and limited to your permissions and data scope. They are not predictions or automated decisions.'];
    }

    public function present(Finding $f): array
    {
        return ['uuid' => $f->uuid, 'domain' => $f->domain, 'insight_key' => $f->insight_key, 'severity' => $f->severity, 'title' => $f->title, 'description' => $f->description, 'count' => $f->count,
            'status' => $f->status, 'status_reason' => $f->status_reason, 'due_on' => $f->due_on?->toDateString(), 'snoozed_until' => $f->snoozed_until?->toIso8601String(),
            'owner' => $f->owner_user_id ? ['id' => $f->owner_user_id, 'name' => User::find($f->owner_user_id)?->name] : null, 'evidence' => $f->evidence, 'occurrences' => $f->occurrences,
            'first_seen_at' => $f->first_seen_at?->toIso8601String(), 'last_seen_at' => $f->last_seen_at?->toIso8601String(), 'has_task' => $f->work_item_id !== null];
    }

    public function explainFor(Finding $f): array
    {
        return ['uuid' => $f->uuid, 'title' => $f->title, 'explain' => $f->explain, 'history' => $this->history($f)];
    }

    public function history(Finding $f): array
    {
        return FindingEvent::where('finding_id', $f->id)->orderBy('id')->get()->map(fn ($e) => ['type' => $e->type, 'from' => $e->from_status, 'to' => $e->to_status, 'reason' => $e->reason,
            'actor' => $e->actor_user_id ? User::find($e->actor_user_id)?->name : 'System', 'at' => $e->created_at?->toIso8601String()])->all();
    }

    /** Manual lifecycle change. snoozed needs a future `until`; snoozed/resolved/dismissed need a reason. */
    public function transition(User $actor, Finding $f, string $to, ?string $reason, ?string $until = null): Finding
    {
        if (! in_array($to, self::STATUSES, true) || ! in_array($to, self::TRANSITIONS[$f->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$f->status} finding cannot become {$to}."]);
        }
        if (in_array($to, ['snoozed', 'resolved', 'dismissed'], true) && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        if ($to === 'snoozed' && (! $until || now()->gte($until))) {
            throw ValidationException::withMessages(['snoozed_until' => 'Choose a future date to snooze until.']);
        }
        $from = $f->status;
        DB::transaction(function () use ($f, $to, $reason, $until, $actor, $from) {
            $f->update(['status' => $to, 'status_reason' => $reason, 'snoozed_until' => $to === 'snoozed' ? $until : null, 'resolved_at' => $to === 'resolved' ? now() : null]);
            $this->event($f, 'status', $from, $to, $reason, $until ? ['until' => $until] : null, $actor->id);
            if (in_array($to, ['resolved', 'dismissed'], true)) {
                $this->tasks->completeBySource('intelligence', 'finding:'.$f->uuid);
            }
            Audit::record('intelligence.finding.'.$to, 'intelligence', 'finding:'.$f->uuid, ['from' => $from, 'reason' => $reason]);
        });

        return $f->refresh();
    }

    public function setDue(User $actor, Finding $f, ?string $due): Finding
    {
        $f->update(['due_on' => $due]);
        $this->event($f, 'due', $f->status, $f->status, null, ['due_on' => $due], $actor->id);

        return $f;
    }

    /** @return array{acknowledged:int,skipped:int} */
    public function bulkAcknowledge(User $actor, array $uuids): array
    {
        $done = 0;
        foreach (Finding::where('audience_user_id', $actor->id)->whereIn('uuid', $uuids)->where('status', 'open')->get() as $f) {
            $this->transition($actor, $f, 'acknowledged', null);
            $done++;
        }

        return ['acknowledged' => $done, 'skipped' => count(array_unique($uuids)) - $done];
    }

    /** Converts the finding into one My Work task for its owner (createOnce: repeated calls never duplicate). */
    public function createTask(User $actor, Finding $f): array
    {
        $entity = 'finding:'.$f->uuid;
        $action = $f->evidence['actions'][0] ?? null;
        $task = $this->tasks->createOnce(['assignee_id' => $f->owner_user_id ?? $actor->id, 'title' => 'Review: '.$f->title, 'description' => $f->description, 'source_module' => 'intelligence', 'source_entity' => $entity,
            'priority' => in_array($f->severity, ['critical', 'action_required'], true) ? 'high' : 'normal', 'due_date' => $f->due_on?->toDateString(), 'action' => $action['href'] ?? null, 'metadata' => ['finding_uuid' => $f->uuid, 'domain' => $f->domain]]);
        $created = $task !== null;
        $task ??= WorkItem::where('source_module', 'intelligence')->where('source_entity', $entity)->where('status', 'open')->first();
        if ($task) {
            $f->update(['work_item_id' => $task->id]);
            if ($created) {
                $this->event($f, 'task_created', $f->status, $f->status, null, ['work_item_id' => $task->id], $actor->id);
            }
        }

        return ['created' => $created, 'task_id' => $task?->id, 'finding' => $this->present($f->refresh())];
    }

    private function event(Finding $f, string $type, ?string $from, ?string $to, ?string $reason, ?array $meta = null, ?int $actor = null): void
    {
        FindingEvent::create(['finding_id' => $f->id, 'type' => $type, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_user_id' => $actor, 'meta' => $meta, 'created_at' => now()]);
    }
}
