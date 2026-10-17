<?php

namespace App\Domain\Security\Compliance;

use App\Domain\Audit\Audit;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Continuous Compliance Evidence Vault. A control is a recurring obligation the company defines (it ships with no statutory calendar: due dates
 * and rules change and must come from the company's own advisor). Each cycle needs evidence (a reference to where the proof lives), and that evidence
 * counts only when someone OTHER than the submitter accepts it. Accepting moves the next due date forward by one period. The auditor pack lists accepted
 * evidence for a period and carries a SHA-256 of its own contents so a later change to the pack is detectable.
 */
final class ComplianceService
{
    public const CATEGORIES = ['statutory', 'policy', 'security', 'other'];

    public const FREQUENCIES = ['monthly' => 'P1M', 'quarterly' => 'P3M', 'half_yearly' => 'P6M', 'annual' => 'P1Y', 'one_time' => null];

    private const DUE_SOON_DAYS = 14;

    public function __construct(private TaskService $tasks) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function status(ComplianceControl $c, int $awaitingReview): string
    {
        if (! $c->active) {
            return 'inactive';
        }
        if ($c->next_due_on === null) {
            return 'complete';
        }
        $days = CarbonImmutable::parse(TenantClock::today())->diffInDays($c->next_due_on, false);
        if ($awaitingReview > 0) {
            return $days < 0 ? 'overdue_in_review' : 'awaiting_review';
        }

        return $days < 0 ? 'overdue' : ($days <= self::DUE_SOON_DAYS ? 'due_soon' : 'current');
    }

    public function present(ComplianceControl $c): array
    {
        $awaiting = ComplianceEvidence::where('control_id', $c->id)->where('status', 'submitted')->count();
        $last = ComplianceEvidence::where('control_id', $c->id)->where('status', 'accepted')->orderByDesc('collected_on')->first();
        $days = $c->next_due_on ? CarbonImmutable::parse(TenantClock::today())->diffInDays($c->next_due_on, false) : null;

        return ['uuid' => $c->uuid, 'code' => $c->code, 'name' => $c->name, 'description' => $c->description, 'category' => $c->category, 'frequency' => $c->frequency, 'regulation_ref' => $c->regulation_ref,
            'owner' => ['id' => $c->owner_user_id, 'name' => User::find($c->owner_user_id)?->name], 'next_due_on' => $c->next_due_on?->toDateString(), 'days_until_due' => $days, 'active' => $c->active,
            'status' => $this->status($c, $awaiting), 'awaiting_review' => $awaiting, 'last_accepted_on' => $last?->collected_on?->toDateString(), 'accepted_count' => ComplianceEvidence::where('control_id', $c->id)->where('status', 'accepted')->count()];
    }

    public function list(): array
    {
        $rows = ComplianceControl::orderByRaw('next_due_on is null')->orderBy('next_due_on')->limit(300)->get()->map(fn ($c) => $this->present($c))->all();
        $count = fn (string $s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));

        return ['items' => $rows, 'summary' => ['overdue' => $count('overdue') + $count('overdue_in_review'), 'due_soon' => $count('due_soon'), 'awaiting_review' => $count('awaiting_review') + $count('overdue_in_review'), 'current' => $count('current')],
            'note' => 'This register starts empty. Statutory due dates and rules vary and change, so each control and its date should come from your own compliance advisor.'];
    }

    public function find(string $uuid): ComplianceControl
    {
        return ComplianceControl::where('uuid', $uuid)->firstOrFail();
    }

    private function owner(User $u, int $id): User
    {
        $owner = User::where('tenant_id', $u->tenant_id)->where('active', true)->find($id);

        return $owner ?? $this->fail('owner_user_id', 'Choose an active user in your company.');
    }

    public function create(User $u, array $d): ComplianceControl
    {
        $code = strtoupper($d['code']);
        if (ComplianceControl::where('code', $code)->exists()) {
            $this->fail('code', 'A control with this code already exists.');
        }
        $this->owner($u, $d['owner_user_id']);
        if ($d['frequency'] !== 'one_time' && empty($d['next_due_on'])) {
            $this->fail('next_due_on', 'Set the first due date for a recurring control.');
        }
        $c = ComplianceControl::create(['code' => $code, 'name' => $d['name'], 'description' => $d['description'] ?? null, 'category' => $d['category'], 'frequency' => $d['frequency'], 'regulation_ref' => $d['regulation_ref'] ?? null,
            'owner_user_id' => $d['owner_user_id'], 'next_due_on' => $d['next_due_on'] ?? null, 'active' => true, 'created_by' => $u->id]);
        Audit::record('compliance.control.create', 'security', 'control:'.$c->uuid, ['code' => $code]);

        return $c;
    }

    public function update(User $u, ComplianceControl $c, array $d): ComplianceControl
    {
        if (isset($d['owner_user_id'])) {
            $this->owner($u, $d['owner_user_id']);
        }
        $c->update(array_intersect_key($d, array_flip(['name', 'description', 'regulation_ref', 'owner_user_id', 'next_due_on', 'active'])));
        Audit::record('compliance.control.update', 'security', 'control:'.$c->uuid, array_keys($d));

        return $c->refresh();
    }

    public function submit(User $u, ComplianceControl $c, array $d): ComplianceEvidence
    {
        if (! $c->active) {
            $this->fail('control', 'This control is inactive.');
        }
        if ($c->owner_user_id !== $u->id && ! $u->allows('security.compliance.manage')) {
            abort(403, 'Only the control owner or a compliance manager can submit evidence.');
        }
        if (CarbonImmutable::parse($d['collected_on'])->gt(CarbonImmutable::parse(TenantClock::today()))) {
            $this->fail('collected_on', 'The evidence cannot be dated in the future.');
        }
        if (ComplianceEvidence::where('control_id', $c->id)->where('status', 'submitted')->exists()) {
            $this->fail('control', 'There is already evidence waiting for review on this control.');
        }
        $e = ComplianceEvidence::create(['control_id' => $c->id, 'covers_due_on' => $c->next_due_on, 'collected_on' => $d['collected_on'], 'title' => $d['title'], 'reference' => $d['reference'], 'note' => $d['note'] ?? null, 'status' => 'submitted', 'submitted_by' => $u->id]);
        Audit::record('compliance.evidence.submit', 'security', 'evidence:'.$e->uuid, ['control' => $c->code]);

        return $e;
    }

    /** Independent review. Accepting advances the control by one period from the due date the evidence covered. */
    public function review(User $u, string $evidenceUuid, bool $accept, ?string $note): ComplianceEvidence
    {
        $e = ComplianceEvidence::where('uuid', $evidenceUuid)->firstOrFail();
        if ($e->status !== 'submitted') {
            $this->fail('status', "This evidence is already {$e->status}.");
        }
        if ($e->submitted_by === $u->id) {
            abort(403, 'Someone other than the submitter must review this evidence.');
        }
        if (! $accept && trim((string) $note) === '') {
            $this->fail('note', 'Say what is missing so it can be resubmitted.');
        }
        DB::transaction(function () use ($u, $e, $accept, $note) {
            $e->update(['status' => $accept ? 'accepted' : 'rejected', 'reviewed_by' => $u->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $c = ComplianceControl::findOrFail($e->control_id);
            if ($accept) {
                $step = self::FREQUENCIES[$c->frequency];
                $c->update(['next_due_on' => $step && $e->covers_due_on ? CarbonImmutable::parse($e->covers_due_on)->add(new \DateInterval($step))->toDateString() : null]);
            }
            $this->tasks->completeBySource('compliance', 'control:'.$c->uuid);
            Audit::record('compliance.evidence.'.($accept ? 'accept' : 'reject'), 'security', 'evidence:'.$e->uuid, ['control' => $c->code, 'note' => $note]);
        });

        return $e->refresh();
    }

    public function presentEvidence(ComplianceEvidence $e): array
    {
        return ['uuid' => $e->uuid, 'title' => $e->title, 'reference' => $e->reference, 'note' => $e->note, 'status' => $e->status, 'collected_on' => $e->collected_on?->toDateString(), 'covers_due_on' => $e->covers_due_on?->toDateString(),
            'submitted_by' => User::find($e->submitted_by)?->name, 'reviewed_by' => $e->reviewed_by ? User::find($e->reviewed_by)?->name : null, 'reviewed_at' => $e->reviewed_at?->toIso8601String(), 'review_note' => $e->review_note];
    }

    public function evidenceFor(ComplianceControl $c): array
    {
        return ComplianceEvidence::where('control_id', $c->id)->orderByDesc('id')->limit(100)->get()->map(fn ($e) => $this->presentEvidence($e))->all();
    }

    public function awaitingReview(User $u): array
    {
        return ComplianceEvidence::where('status', 'submitted')->orderBy('id')->limit(100)->get()->map(fn ($e) => $this->presentEvidence($e) + [
            'control' => ComplianceControl::find($e->control_id)?->only(['code', 'name']), 'can_review' => $e->submitted_by !== $u->id])->all();
    }

    /** Opens one My Work task per control that is overdue or due within a week (never duplicated). */
    public function remind(User $u): array
    {
        $created = 0;
        $skipped = 0;
        $today = CarbonImmutable::parse(TenantClock::today());
        foreach (ComplianceControl::where('active', true)->whereNotNull('next_due_on')->where('next_due_on', '<=', $today->addDays(7)->toDateString())->get() as $c) {
            if (ComplianceEvidence::where('control_id', $c->id)->where('status', 'submitted')->exists()) {
                continue;
            }
            $overdue = $c->next_due_on->lt($today);
            $t = $this->tasks->createOnce(['assignee_id' => $c->owner_user_id, 'title' => ($overdue ? 'Overdue: ' : 'Due soon: ').$c->name, 'description' => 'Due '.$c->next_due_on->toDateString().'. Submit evidence for review.',
                'source_module' => 'compliance', 'source_entity' => 'control:'.$c->uuid, 'priority' => $overdue ? 'high' : 'normal', 'due_date' => $c->next_due_on->toDateString(), 'action' => '/security/compliance', 'metadata' => ['control_uuid' => $c->uuid]]);
            $t ? $created++ : $skipped++;
        }

        return ['created' => $created, 'already_open' => $skipped];
    }

    /** Accepted evidence collected in [from, to], with a checksum over the content so later edits to a saved pack are visible. */
    public function pack(User $u, string $from, string $to): array
    {
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                $this->fail($k, 'Use a valid date (YYYY-MM-DD).');
            }
        }
        if ($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 400) {
            $this->fail('to', 'Choose a period of up to 400 days, ending on or after the start.');
        }
        $controls = [];
        foreach (ComplianceControl::orderBy('code')->get() as $c) {
            $ev = ComplianceEvidence::where('control_id', $c->id)->where('status', 'accepted')->whereBetween('collected_on', [$from, $to])->orderBy('collected_on')->get();
            $controls[] = ['code' => $c->code, 'name' => $c->name, 'category' => $c->category, 'frequency' => $c->frequency, 'regulation_ref' => $c->regulation_ref, 'owner' => User::find($c->owner_user_id)?->name,
                'evidence' => $ev->map(fn ($e) => ['title' => $e->title, 'reference' => $e->reference, 'collected_on' => $e->collected_on->toDateString(), 'covers_due_on' => $e->covers_due_on?->toDateString(),
                    'submitted_by' => User::find($e->submitted_by)?->name, 'accepted_by' => User::find($e->reviewed_by)?->name, 'accepted_at' => $e->reviewed_at?->toIso8601String()])->all(), 'gap' => $c->active && $ev->isEmpty()];
        }
        $body = ['period' => ['from' => $from, 'to' => $to], 'controls' => $controls];
        $sha = hash('sha256', json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Audit::record('compliance.pack', 'security', 'user:'.$u->id, ['from' => $from, 'to' => $to, 'sha256' => $sha]);

        return $body + ['generated_at' => now()->toIso8601String(), 'sha256' => $sha, 'controls_without_evidence' => count(array_filter($controls, fn ($c) => $c['gap'])),
            'note' => 'The checksum covers the period and the controls with their accepted evidence. It shows the pack has not been altered since it was generated. It does not prove the referenced documents are genuine.'];
    }
}
