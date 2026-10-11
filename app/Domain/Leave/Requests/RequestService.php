<?php

namespace App\Domain\Leave\Requests;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Models\LeaveConflict;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestAction;
use App\Domain\Leave\Models\LeaveRequestDocument;
use App\Domain\Leave\Models\LeaveRequestSegment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Leave requests: submit (with ledger reservations), withdraw, cancel (whole or per day), controlled modification, documents.
 * Approvals live in DecisionService; both write the auditable history in leave_request_actions.
 */
final class RequestService
{
    public const OPEN = ['pending', 'approved', 'partially_cancelled'];

    public function __construct(
        private RequestPlanner $planner,
        private RequestLedger $ledger,
        private LeaveApprovers $approvers,
        private LeaveSupport $support,
        private DomainEvents $events,
    ) {}

    /** @return array{0:LeaveRequest,1:bool} the request and whether it was newly created (false = idempotent replay) */
    public function submit(User $actor, Employee $employee, array $in, ?string $idempotencyKey = null, array $files = [], ?LeaveRequest $parent = null): array
    {
        if ($idempotencyKey && ($existing = LeaveRequest::query()->where('idempotency_key', $idempotencyKey)->first())) {
            abort_unless($existing->employee_id === $employee->id, 409, 'That idempotency key was already used for a different request.');

            return [$existing, false];
        }
        $plan = $this->planner->plan($actor, $employee, $in, $parent, count($files));
        $duration = $plan['duration'];
        if (! $duration->valid) {
            throw new RequestRejected($duration->errors[0], ['errors' => $duration->errors, 'duration' => $duration->toArray(), 'conflicts' => $plan['conflicts']]);
        }
        if ($plan['summary']['blocked']) {
            $blocks = array_values(array_filter($plan['conflicts'], fn ($c) => $c['severity'] === 'block'));
            throw new RequestRejected($blocks[0]['message'], ['errors' => array_column($blocks, 'message'), 'conflicts' => $plan['conflicts'], 'duration' => $duration->toArray()]);
        }
        if ($plan['first_approvers']->isEmpty()) {
            throw new RequestRejected('No approver is available for this leave request. Please ask HR to assign a reporting manager.', ['errors' => ['No approver is available.']]);
        }
        $data = $plan['data'];
        $type = $plan['type'];
        $statutory = $plan['statutory'];
        $approver = $plan['first_approvers']->first();
        $roles = $plan['roles'];

        $request = DB::transaction(function () use ($actor, $employee, $plan, $duration, $data, $type, $statutory, $approver, $roles, $idempotencyKey, $files, $parent) {
            $warnings = array_merge($duration->warnings, array_map(fn ($c) => ['kind' => $c['kind'], 'severity' => $c['severity'], 'message' => $c['message']], array_filter($plan['conflicts'], fn ($c) => $c['severity'] !== 'block')));
            $request = LeaveRequest::create([
                'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'policy_version_id' => $duration->policyVersionId, 'status' => 'pending', 'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
                'start_time' => $data['start_time'] ?? null, 'end_time' => $data['end_time'] ?? null, 'timezone' => $data['timezone'] ?? null, 'unit' => $type->unit, 'portion' => $data['portion'],
                'requested_units' => $duration->calendarUnits, 'charged_units' => $duration->chargedUnits, 'projected_balance' => $this->projectedTotal($plan['projection']), 'reason' => $data['reason'] ?? null,
                'is_sensitive' => (bool) $type->is_sensitive, 'delegate_employee_id' => $plan['delegate']?->id, 'handover_notes' => $data['handover_notes'] ?? null, 'current_step' => 1, 'total_steps' => count($roles),
                'approver_user_id' => $approver->id, 'requested_by' => $actor->id, 'parent_request_id' => $parent?->id, 'idempotency_key' => $idempotencyKey,
                'confidence' => $plan['summary']['escalate'] ? 'review_required' : 'pending', 'warnings' => $warnings,
                'explanation' => ['summary' => $duration->explanation, 'approval' => ['roles' => $roles, 'escalation_reasons' => $plan['escalation_reasons'], 'escalated' => false], 'rules' => ['policy_version_id' => $duration->policyVersionId]],
                'submitted_at' => now(),
            ]);
            $first = null;
            foreach ($duration->segments as $s) {
                $segment = LeaveRequestSegment::create(['request_id' => $request->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'leave_date' => $s->date, 'starts_at' => $s->startsAt, 'ends_at' => $s->endsAt,
                    'portion' => $s->portion, 'minutes' => $s->minutes, 'units' => $s->units, 'pay_class' => $s->payClass, 'day_kind' => $s->dayKind, 'charged' => $s->charged, 'status' => 'active',
                    'explanation' => ['text' => $s->explanation, 'detail' => $s->detail]]);
                if ($type->tracks_balance && $s->charged && ! Units::isZero($s->units)) {
                    $account = app(AccountLocator::class)->forDate($employee->id, $type->id, $s->date);
                    $enforce = ! $statutory && ! $parent;
                    $this->ledger->reserve($request, $segment, $account, $enforce, $actor->id, ['rule' => 'leave_request', 'portion' => $s->portion, 'day_kind' => $s->dayKind, 'explanation' => $s->explanation]);
                }
                $first ??= $segment;
            }
            foreach ($plan['conflicts'] as $c) {
                LeaveConflict::create(['request_id' => $request->id, 'restriction_id' => $c['restriction_id'] ?? null, 'kind' => $c['kind'], 'severity' => $c['severity'], 'message' => mb_substr($c['message'], 0, 500), 'detail' => $c['detail'] ?? null]);
            }
            foreach ($files as $file) {
                $this->storeDocument($actor, $request, $type, $file, null);
            }
            $this->log($request, $actor->id, 'submit', 1, null, 'pending', null, null, ['charged_units' => $duration->chargedUnits, 'dates' => [$data['start_date'], $data['end_date']]]);
            if ($parent) {
                $this->log($parent, $actor->id, 'modify', null, $parent->status, $parent->status, 'A modification was requested: '.$request->uuid, null, ['new_request' => $request->uuid]);
            }
            $this->notifyApprovers($request, $employee, $type, 1);
            Audit::record('requested', 'leave', 'request:'.$request->uuid, ['employee' => $employee->uuid, 'type' => $type->code, 'start' => $data['start_date'], 'end' => $data['end_date'], 'units' => $duration->chargedUnits]);
            $this->events->record(LeaveEvents::REQUEST_SUBMITTED, ['employee' => $employee, 'subject' => ['leave_request', $request->id], 'summary' => 'Leave requested for '.$data['start_date'].($data['end_date'] !== $data['start_date'] ? ' to '.$data['end_date'] : ''),
                'payload' => ['uuid' => $request->uuid, 'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'units' => $duration->chargedUnits, 'modification_of' => $parent?->uuid], 'effective_date' => $data['start_date']]);

            return $request->refresh();
        });

        return [$request, true];
    }

    /** Pending only: the requester (or the employee) takes it back; every hold is released. */
    public function withdraw(User $actor, LeaveRequest $request, ?string $comment = null): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $request, $comment) {
            $r = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $employee = Employee::query()->findOrFail($r->employee_id);
            abort_unless($r->status === 'pending', 409, $r->status === 'approved' ? 'This request is already approved; cancel it instead.' : "This request is already {$r->status}.");
            abort_unless($r->requested_by === $actor->id || $this->support->scope()->isSelf($actor, $employee), 403, 'Only the person who raised the request can withdraw it.');
            $this->releaseAll($r, $actor->id, 'Withdrawn');
            $r->update(['status' => 'withdrawn', 'decided_at' => now()]);
            $this->log($r, $actor->id, 'withdraw', (int) $r->current_step, 'pending', 'withdrawn', $comment);
            $this->support->completeTasks('leave:'.$r->uuid);
            $this->support->notify($r->approver_user_id, 'Leave request withdrawn', 'A leave request waiting for you was withdrawn.', "leave:{$r->uuid}:withdrawn", '/leave/approvals');
            $this->events->record(LeaveEvents::REQUEST_WITHDRAWN, ['employee' => $employee, 'subject' => ['leave_request', $r->id], 'summary' => 'Leave request withdrawn', 'payload' => ['uuid' => $r->uuid], 'effective_date' => $r->start_date]);
            Audit::record('withdrawn', 'leave', 'request:'.$r->uuid);

            return $r->refresh();
        });
    }

    /** Expire a pending request after its tenant-configured window; safe to call repeatedly from maintenance. */
    public function expireIfOverdue(LeaveRequest $request, ?int $days = null): bool
    {
        $days ??= LeaveSettings::get('requests.pending_expiry_days', 90);
        if (! $days || $days < 1) {
            return false;
        }

        return DB::transaction(function () use ($request, $days) {
            $r = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending') {
                return false;
            }
            $submitted = CarbonImmutable::parse($r->submitted_at ?? $r->created_at);
            if ($submitted->addDays($days)->isFuture()) {
                return false;
            }

            $this->releaseAll($r, null, 'Expired after '.$days.' days without a decision');
            $r->update(['status' => 'expired', 'decided_at' => now(), 'charged_units' => '0.0000']);
            $this->log($r, null, 'expire', (int) $r->current_step, 'pending', 'expired', 'No decision before the configured expiry window.', null, ['expiry_days' => $days]);
            $this->support->completeTasks('leave:'.$r->uuid);
            $employee = Employee::query()->find($r->employee_id);
            $this->support->notify($employee ? $this->approvers->userIdOf($employee) : null, 'Leave request expired', 'Your leave request from '.$r->start_date.' expired without a decision. Submit it again if it is still needed, or contact HR.', "leave:{$r->uuid}:expired", '/leave/my');
            Audit::record('expired', 'leave', 'request:'.$r->uuid, ['expiry_days' => $days]);

            return true;
        });
    }

    /**
     * Cancel a request or just some of its days. Pending: the holds for those days are released. Approved: the consumption is
     * reversed (never edited) and Attendance is told the coverage changed. Past days of approved leave, and anything in a locked
     * period, need HR (leave.manage) / are refused by the lock.
     *
     * @param  array<int,string>|null  $dates  null = everything still active
     */
    public function cancel(User $actor, LeaveRequest $request, ?array $dates = null, ?string $reason = null): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $request, $dates, $reason) {
            $r = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $employee = Employee::query()->findOrFail($r->employee_id);
            abort_unless(in_array($r->status, self::OPEN, true), 409, "This request is {$r->status} and cannot be cancelled.");
            $self = $this->support->scope()->isSelf($actor, $employee);
            $hr = $actor->allows('leave.manage') && $this->support->scope()->canAccess($actor, $employee, 'leave.manage');
            $manager = $actor->allows('leave.approve') && ($this->support->scope()->isManagerOf($actor, $employee) || $this->approvers->canDecide($actor, $r, $employee));
            abort_unless($self || $hr || $manager, 404, 'That request was not found.');
            $reason = $reason !== null ? trim($reason) : null;
            if (! $self && ($reason === null || $reason === '')) {
                throw ValidationException::withMessages(['reason' => 'Tell the employee why the leave is being cancelled.']);
            }
            $active = LeaveRequestSegment::query()->where('request_id', $r->id)->where('status', 'active')->orderBy('leave_date')->get();
            $set = $dates ? $active->whereIn('leave_date', $dates) : $active;
            if ($set->isEmpty()) {
                throw ValidationException::withMessages(['dates' => 'None of those dates is still part of this request.']);
            }
            $today = TenantClock::today($employee->timezone ?: null);
            if ($r->status !== 'pending' && $self && ! $hr) {
                abort_unless(LeaveSettings::get('requests.allow_withdraw_after_approval', true), 403, 'Approved leave cannot be cancelled by the employee. Ask your manager or HR.');
                abort_if($set->contains(fn ($s) => $s->leave_date < $today), 403, 'Days that have already passed can only be cancelled by HR.');
            }
            $wasApproved = $r->status !== 'pending';
            $before = ['charged_units' => $r->getRawOriginal('charged_units')];
            foreach ($set as $s) {
                if ($s->charged) {
                    $this->ledger->release($r, $s, $actor->id, $reason ? 'Cancelled: '.mb_substr($reason, 0, 400) : 'Cancelled by the employee');
                }
                $s->update(['status' => 'cancelled']);
            }
            $remaining = LeaveRequestSegment::query()->where('request_id', $r->id)->where('status', 'active')->where('charged', true)->get();
            $charged = Units::sum($remaining->map(fn ($s) => $s->getRawOriginal('units')));
            $full = $remaining->isEmpty();
            $new = $full ? 'cancelled' : ($r->status === 'pending' ? 'pending' : 'partially_cancelled');
            $r->update(['status' => $new, 'charged_units' => $charged, 'decided_at' => $r->status === 'pending' && ! $full ? $r->decided_at : now()]);
            $this->log($r, $actor->id, $full ? 'cancel' : 'partial_cancel', null, $request->status, $new, $reason, $before, ['charged_units' => $charged, 'cancelled_dates' => $set->pluck('leave_date')->all()]);
            if ($full) {
                $this->support->completeTasks('leave:'.$r->uuid);
            }
            if ($wasApproved) {
                $this->support->coverageChanged($employee, $set->where('charged', true)->pluck('leave_date')->all(), $r->uuid, $actor->id, 'cancelled');
            }
            $employeeUser = $this->approvers->userIdOf($employee);
            if ($employeeUser && $employeeUser !== $actor->id) {
                $this->support->notify($employeeUser, $full ? 'Leave cancelled' : 'Leave partly cancelled', 'Your leave starting '.$r->start_date.' was '.($full ? 'cancelled' : 'partly cancelled').'.', "leave:{$r->uuid}:cancel:".sha1(json_encode($set->pluck('leave_date'))), '/leave/my');
            }
            if ($r->approver_user_id && $r->approver_user_id !== $actor->id && $wasApproved) {
                $this->support->notify($r->approver_user_id, 'Approved leave cancelled', 'Approved leave starting '.$r->start_date.' was '.($full ? 'cancelled' : 'partly cancelled').'.', "leave:{$r->uuid}:cancel-approver:".sha1(json_encode($set->pluck('leave_date'))), '/leave/approvals');
            }
            $this->events->record(LeaveEvents::REQUEST_CANCELLED, ['employee' => $employee, 'subject' => ['leave_request', $r->id], 'summary' => $full ? 'Leave cancelled' : 'Leave partly cancelled',
                'payload' => ['uuid' => $r->uuid, 'dates' => $set->pluck('leave_date')->all(), 'full' => $full], 'effective_date' => $r->start_date]);
            Audit::record($full ? 'cancelled' : 'partially_cancelled', 'leave', 'request:'.$r->uuid, ['dates' => $set->pluck('leave_date')->all()]);

            return $r->refresh();
        });
    }

    /**
     * Controlled modification of approved leave: a NEW request that references the original. The original stays in force until
     * the modification is approved; only then is it reversed and replaced (no silent edits, balances never double count).
     */
    public function modify(User $actor, LeaveRequest $request, array $in, ?string $idempotencyKey = null, array $files = []): LeaveRequest
    {
        $employee = Employee::query()->findOrFail($request->employee_id);
        abort_unless($this->support->scope()->isSelf($actor, $employee) || $actor->allows('leave.manage') && $this->support->scope()->canAccess($actor, $employee, 'leave.manage'), 404, 'That request was not found.');
        abort_unless(in_array($request->status, ['approved', 'partially_cancelled'], true), 409, 'Only approved leave can be modified. Withdraw a pending request and submit a new one instead.');
        abort_if(LeaveRequest::query()->where('parent_request_id', $request->id)->where('status', 'pending')->exists(), 409, 'A change to this leave is already waiting for approval.');
        $in += ['leave_type' => LeaveType::query()->whereKey($request->leave_type_id)->value('uuid'), 'start_date' => $request->start_date, 'end_date' => $request->end_date, 'portion' => $request->portion,
            'delegate_employee' => $request->delegate_employee_id ? Employee::query()->whereKey($request->delegate_employee_id)->value('uuid') : null];

        return $this->submit($actor, $employee, $in, $idempotencyKey, $files, $request)[0];
    }

    // ---- documents -------------------------------------------------------------------------------

    public function addDocument(User $actor, LeaveRequest $request, UploadedFile $file, ?string $kind = null): LeaveRequestDocument
    {
        $employee = Employee::query()->findOrFail($request->employee_id);
        abort_unless($this->support->scope()->isSelf($actor, $employee) || $request->requested_by === $actor->id || $actor->allows('leave.manage') && $this->support->scope()->canAccess($actor, $employee, 'leave.manage'), 404, 'That request was not found.');
        abort_unless(in_array($request->status, self::OPEN, true), 409, "This request is {$request->status}; documents can no longer be added.");
        $type = LeaveType::query()->findOrFail($request->leave_type_id);

        return DB::transaction(function () use ($actor, $request, $file, $kind, $type) {
            $doc = $this->storeDocument($actor, $request, $type, $file, $kind);
            $this->log($request, $actor->id, 'comment', null, $request->status, $request->status, 'A document was attached.', null, ['document' => $doc->uuid]);
            Audit::record('document_added', 'leave', 'request:'.$request->uuid, ['document' => $doc->uuid]);
            // a submitted document satisfies an open "submit later" document warning
            LeaveConflict::query()->where('request_id', $request->id)->where('kind', 'document')->whereNull('resolved_at')->update(['resolved_by' => $actor->id, 'resolved_at' => now()]);

            return $doc;
        });
    }

    private function storeDocument(User $actor, LeaveRequest $request, LeaveType $type, UploadedFile $file, ?string $kind): LeaveRequestDocument
    {
        $uuid = (string) Str::uuid();
        $stored = $this->support->storeFile($file, $uuid);
        $sensitive = (bool) $type->is_sensitive;
        $retention = $sensitive ? (int) LeaveSettings::get('privacy.medical_retention_days', 2555) : null;

        return LeaveRequestDocument::create(['uuid' => $uuid, 'request_id' => $request->id, 'kind' => $kind ?: ($sensitive ? 'medical' : 'supporting'), 'filename' => $stored['name'], 'path' => $stored['path'], 'mime' => $stored['mime'],
            'size_bytes' => $stored['size'], 'is_sensitive' => $sensitive, 'retain_until' => $retention ? CarbonImmutable::parse(TenantClock::today())->addDays($retention)->toDateString() : null, 'uploaded_by' => $actor->id]);
    }

    // ---- shared helpers (used by DecisionService too) ----------------------------------------------

    public function releaseAll(LeaveRequest $r, ?int $actorId, string $reason): void
    {
        foreach (LeaveRequestSegment::query()->where('request_id', $r->id)->where('status', 'active')->get() as $s) {
            if ($s->charged) {
                $this->ledger->release($r, $s, $actorId, $reason);
            }
            $s->update(['status' => 'cancelled']);
        }
    }

    public function log(LeaveRequest $r, ?int $actorId, string $action, ?int $step, ?string $from, ?string $to, ?string $comment = null, ?array $before = null, ?array $after = null): LeaveRequestAction
    {
        return LeaveRequestAction::create(['request_id' => $r->id, 'actor_user_id' => $actorId, 'action' => $action, 'step' => $step, 'from_status' => $from, 'to_status' => $to, 'comment' => $comment, 'before' => $before, 'after' => $after]);
    }

    /** Assigns the My Work task to everyone who may decide this step. Titles never reveal a sensitive leave type. */
    public function notifyApprovers(LeaveRequest $r, Employee $employee, LeaveType $type, int $step): void
    {
        $name = $employee->person?->display_name ?? $employee->employee_number;
        $label = $type->is_sensitive ? 'leave' : strtolower($type->name);
        $span = $r->start_date.($r->end_date !== $r->start_date ? ' to '.$r->end_date : '');
        $due = CarbonImmutable::parse(TenantClock::today())->addDays(max(1, intdiv((int) LeaveSettings::get('approvals.escalate_after_hours', 48), 24)))->toDateString();
        $this->support->assignTasks($this->approvers->candidatesFor($r, $employee, $step), "Approve $label: $name, $span (".rtrim(rtrim(Units::normalize($r->getRawOriginal('charged_units')), '0'), '.').')',
            $step > 1 ? 'Step '.$step.' of '.$r->total_steps.'.' : 'Waiting for your decision.', 'leave:'.$r->uuid, '/leave/approvals?request='.$r->uuid, $due, ['kind' => 'leave', 'uuid' => $r->uuid, 'step' => $step]);
    }

    private function projectedTotal(array $projection): ?string
    {
        if (empty($projection['tracked']) || empty($projection['accounts'])) {
            return null;
        }

        return Units::sum(array_column($projection['accounts'], 'projected'));
    }
}
