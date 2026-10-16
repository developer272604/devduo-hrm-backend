<?php

namespace App\Domain\Intelligence\Leakage;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Intelligence\Models\LeakageCase;
use App\Domain\Intelligence\Models\LeakageEvent;
use App\Domain\Intelligence\Models\LeakageSettings;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persists detector output as reviewable cases and runs the human review lifecycle. The system never decides: a case moves to
 * confirmed / false_positive / recovered only by a person who must give a reason. Re-detection updates evidence but never reopens a closed case.
 */
final class LeakageCaseService
{
    public const STATUSES = ['open', 'under_review', 'confirmed', 'false_positive', 'recovered'];

    public const TRANSITIONS = [
        'open' => ['under_review', 'confirmed', 'false_positive'],
        'under_review' => ['open', 'confirmed', 'false_positive'],
        'confirmed' => ['recovered', 'under_review'],
        'false_positive' => ['open'],
        'recovered' => [],
    ];

    private const NEEDS_REASON = ['confirmed', 'false_positive', 'recovered'];

    public function __construct(private LeakageDetector $detector, private DataScope $scope, private TaskService $tasks) {}

    /** Detects over [from, to] (default: month to date) and reconciles stored cases. Idempotent within a calendar month. */
    public function scan(User $viewer, ?string $from = null, ?string $to = null): array
    {
        $end = $to ? CarbonImmutable::parse($to) : CarbonImmutable::parse(TenantClock::today());
        $start = $from ? CarbonImmutable::parse($from) : $end->startOfMonth();
        if ($start->gt($end) || $start->diffInDays($end) > 92) {
            throw ValidationException::withMessages(['from' => 'Choose a window of 1 to 93 days.']);
        }
        $summary = ['detected' => 0, 'created' => 0, 'updated' => 0, 'unchanged_closed' => 0];
        $now = now();
        foreach ($this->detector->detect($viewer, $start, $end) as $c) {
            $summary['detected']++;
            $case = LeakageCase::where('fingerprint', $c['fingerprint'])->first();
            if (! $case) {
                $case = LeakageCase::create($c + ['status' => 'open', 'first_seen_at' => $now, 'last_seen_at' => $now]);
                $this->event($case, 'detected', null, 'open', 'First detected by rule '.$c['rule_code'].'.', null, $viewer->id);
                $summary['created']++;

                continue;
            }
            if (in_array($case->status, ['confirmed', 'false_positive', 'recovered'], true)) {
                $case->update(['last_seen_at' => $now]);
                $summary['unchanged_closed']++;

                continue;
            }
            $case->fill(['evidence' => $c['evidence'], 'explain' => $c['explain'], 'title' => $c['title'], 'summary' => $c['summary'], 'exposure_amount' => $c['exposure_amount'],
                'exposure_minutes' => $c['exposure_minutes'], 'window_from' => $c['window_from'], 'window_to' => $c['window_to'], 'severity' => $c['severity'], 'last_seen_at' => $now]);
            $case->detections++;
            $case->save();
            $summary['updated']++;
        }
        Audit::record('intelligence.leakage.scan', 'intelligence', 'user:'.$viewer->id, $summary + ['from' => $start->toDateString(), 'to' => $end->toDateString()]);

        return $summary + ['window' => ['from' => $start->toDateString(), 'to' => $end->toDateString()]];
    }

    private function visible(User $viewer)
    {
        $q = LeakageCase::query();
        if ($this->scope->hasTenantWide($viewer, LeakageDetector::PERMISSION)) {
            return $q;
        }
        $ids = $this->scope->constrain(DB::table('employees')->where('employees.tenant_id', $viewer->tenant_id), $viewer, LeakageDetector::PERMISSION)->pluck('employees.id');

        return $q->whereIn('employee_id', $ids);
    }

    public function find(User $viewer, string $uuid): LeakageCase
    {
        return $this->visible($viewer)->where('uuid', $uuid)->firstOrFail();
    }

    public function list(User $viewer, array $f): array
    {
        $status = $f['status'] ?? 'active';
        $rows = $this->visible($viewer)
            ->when($status === 'active', fn ($q) => $q->whereIn('status', ['open', 'under_review', 'confirmed']))
            ->when($status !== 'active' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($f['rule'] ?? null, fn ($q, $r) => $q->where('rule_code', $r))
            ->orderByDesc('last_seen_at')->limit(300)->get();

        return ['count' => $rows->count(), 'items' => $rows->map(fn ($c) => $this->present($c))->all(), 'summary' => $this->summarize($viewer),
            'rules' => collect(LeakageRules::CATALOG)->map(fn ($r, $k) => ['code' => $k, 'label' => $r['label'], 'description' => $r['description'], 'sources' => $r['sources'], 'enabled' => ! in_array($k, LeakageRules::disabled(), true)])->values()->all(),
            'limitations' => 'Cases are deterministic leads limited to your data scope. They do not establish error, fraud or intent; exposure is an estimate until a reviewer confirms it.'];
    }

    public function summarize(User $viewer): array
    {
        $all = $this->visible($viewer)->get();
        $open = $all->whereIn('status', ['open', 'under_review']);
        $confirmed = $all->whereIn('status', ['confirmed', 'recovered']);
        $byCurrency = fn ($set, string $col) => $set->whereNotNull('currency')->groupBy('currency')->map(fn ($g) => round((float) $g->sum($col), 4))->all();
        $reviewed = $all->whereIn('status', ['confirmed', 'recovered', 'false_positive']);

        return ['open_cases' => $open->count(), 'open_exposure_by_currency' => $byCurrency($open, 'exposure_amount'), 'open_exposure_minutes' => (int) $open->sum('exposure_minutes'),
            'confirmed_cases' => $confirmed->count(), 'confirmed_exposure_by_currency' => $byCurrency($confirmed, 'exposure_amount'),
            'recovered_by_currency' => $byCurrency($all->where('status', 'recovered'), 'recovered_amount'),
            'by_rule' => $open->groupBy('rule_code')->map->count()->all(),
            'precision' => $reviewed->count() >= 5 ? round($confirmed->count() / $reviewed->count(), 2) : null,
            'precision_note' => 'Share of reviewed cases a person confirmed. Shown once at least 5 cases have been reviewed.'];
    }

    public function present(LeakageCase $c): array
    {
        return ['uuid' => $c->uuid, 'rule_code' => $c->rule_code, 'rule' => LeakageRules::CATALOG[$c->rule_code]['label'] ?? $c->rule_code, 'severity' => $c->severity, 'title' => $c->title, 'summary' => $c->summary,
            'status' => $c->status, 'status_reason' => $c->status_reason, 'window' => ['from' => $c->window_from?->toDateString(), 'to' => $c->window_to?->toDateString()],
            'exposure' => ['amount' => $c->exposure_amount !== null ? (float) $c->exposure_amount : null, 'currency' => $c->currency, 'minutes' => $c->exposure_minutes, 'recovered_amount' => $c->recovered_amount !== null ? (float) $c->recovered_amount : null],
            'owner' => $c->owner_user_id ? ['id' => $c->owner_user_id, 'name' => User::find($c->owner_user_id)?->name] : null, 'evidence' => $c->evidence, 'detections' => $c->detections,
            'first_seen_at' => $c->first_seen_at?->toIso8601String(), 'last_seen_at' => $c->last_seen_at?->toIso8601String(), 'closed_at' => $c->closed_at?->toIso8601String(), 'has_task' => $c->work_item_id !== null];
    }

    public function explainFor(LeakageCase $c): array
    {
        return ['uuid' => $c->uuid, 'title' => $c->title, 'explain' => $c->explain, 'history' => $this->history($c)];
    }

    public function history(LeakageCase $c): array
    {
        return LeakageEvent::where('case_id', $c->id)->orderBy('id')->get()->map(fn ($e) => ['type' => $e->type, 'from' => $e->from_status, 'to' => $e->to_status, 'reason' => $e->reason,
            'actor' => $e->actor_user_id ? User::find($e->actor_user_id)?->name : 'System', 'at' => $e->created_at?->toIso8601String(), 'meta' => $e->meta])->all();
    }

    public function transition(User $actor, LeakageCase $c, string $to, ?string $reason, ?float $recovered = null): LeakageCase
    {
        if (! in_array($to, self::TRANSITIONS[$c->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$c->status} case cannot become {$to}."]);
        }
        if (in_array($to, self::NEEDS_REASON, true) && trim((string) $reason) === '') {
            throw ValidationException::withMessages(['reason' => 'A reason is required.']);
        }
        if ($to === 'recovered' && ($recovered === null || $recovered < 0)) {
            throw ValidationException::withMessages(['recovered_amount' => 'Enter the amount recovered (zero or more).']);
        }
        $from = $c->status;
        DB::transaction(function () use ($actor, $c, $to, $reason, $recovered, $from) {
            $closing = in_array($to, ['false_positive', 'recovered'], true);
            $c->update(['status' => $to, 'status_reason' => $reason, 'closed_at' => $closing ? now() : null, 'recovered_amount' => $to === 'recovered' ? $recovered : $c->recovered_amount]);
            $this->event($c, 'status', $from, $to, $reason, $recovered !== null ? ['recovered_amount' => $recovered] : null, $actor->id);
            if (in_array($to, ['confirmed', 'false_positive', 'recovered'], true)) {
                $this->tasks->completeBySource('intelligence', 'leakage:'.$c->uuid);
            }
            Audit::record('intelligence.leakage.'.$to, 'intelligence', 'leakage:'.$c->uuid, ['from' => $from, 'reason' => $reason]);
        });

        return $c->refresh();
    }

    /** Assigns an owner and opens one My Work task for them (createOnce: repeated calls never duplicate). */
    public function assign(User $actor, LeakageCase $c, User $owner): array
    {
        if ((int) $owner->tenant_id !== (int) $actor->tenant_id) {
            throw ValidationException::withMessages(['owner_user_id' => 'Choose a user in your company.']);
        }
        $entity = 'leakage:'.$c->uuid;
        $task = $this->tasks->createOnce(['assignee_id' => $owner->id, 'title' => 'Review cost leakage: '.$c->title, 'description' => $c->summary, 'source_module' => 'intelligence', 'source_entity' => $entity,
            'priority' => $c->severity === 'action_required' ? 'high' : 'normal', 'action' => '/intelligence/leakage', 'metadata' => ['case_uuid' => $c->uuid, 'rule' => $c->rule_code]]);
        $created = $task !== null;
        $task ??= WorkItem::where('source_module', 'intelligence')->where('source_entity', $entity)->where('status', 'open')->first();
        $from = $c->status;
        $c->update(['owner_user_id' => $owner->id, 'work_item_id' => $task?->id ?? $c->work_item_id, 'status' => $c->status === 'open' ? 'under_review' : $c->status]);
        $this->event($c, 'assigned', $from, $c->status, null, ['owner_user_id' => $owner->id, 'task_created' => $created], $actor->id);
        Audit::record('intelligence.leakage.assign', 'intelligence', $entity, ['owner' => $owner->id]);

        return ['created_task' => $created, 'case' => $this->present($c->refresh())];
    }

    public function settings(): array
    {
        return ['thresholds' => LeakageRules::thresholds(), 'defaults' => LeakageRules::DEFAULTS, 'disabled_rules' => LeakageRules::disabled(),
            'rules' => collect(LeakageRules::CATALOG)->map(fn ($r, $k) => ['code' => $k, 'label' => $r['label'], 'description' => $r['description']])->values()->all()];
    }

    public function saveSettings(User $actor, array $thresholds, array $disabled): array
    {
        $clean = array_intersect_key($thresholds, LeakageRules::DEFAULTS);
        LeakageSettings::query()->updateOrCreate([], ['thresholds' => $clean, 'disabled_rules' => array_values(array_intersect($disabled, array_keys(LeakageRules::CATALOG))), 'updated_by' => $actor->id]);
        Audit::record('intelligence.leakage.settings', 'intelligence', 'tenant:'.$actor->tenant_id, ['thresholds' => $clean, 'disabled' => $disabled]);

        return $this->settings();
    }

    private function event(LeakageCase $c, string $type, ?string $from, ?string $to, ?string $reason, ?array $meta, ?int $actor): void
    {
        LeakageEvent::create(['case_id' => $c->id, 'type' => $type, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason, 'actor_user_id' => $actor, 'meta' => $meta, 'created_at' => now()]);
    }
}
