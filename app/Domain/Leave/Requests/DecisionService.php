<?php

namespace App\Domain\Leave\Requests;

use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestSegment;
use App\Domain\Leave\Models\LeaveReturnToWork;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve / reject / escalate leave. Routing is Attendance's ApproverResolver (through LeaveApprovers); the decision turns the
 * ledger holds into scheduled/consumed usage (or releases them) and tells Attendance the coverage changed.
 */
final class DecisionService
{
    public function __construct(private RequestService $requests, private RequestLedger $ledger, private LeaveApprovers $approvers, private LeaveSupport $support, private DomainEvents $events, private ScheduleResolver $schedules) {}

    public function approve(User $actor, LeaveRequest $request, ?string $comment = null): LeaveRequest
    {
        return DB::transaction(function () use ($actor, $request, $comment) {
            [$r, $employee, $type] = $this->open($actor, $request);
            $step = (int) $r->current_step;
            $onBehalf = $this->approvers->onBehalfOf($actor, $r, $employee);
            $roles = $this->approvers->rolesOf($r, $employee);
            if ($step < (int) $r->total_steps) {
                $next = $step + 1;
                $r->update(['current_step' => $next]);
                $candidates = $this->approvers->candidatesFor($r, $employee, $next);
                if ($candidates->isEmpty()) {
                    throw new RequestRejected('There is nobody to take the next approval step ('.($roles[$next - 1] ?? 'manager').'). Ask HR to fix the approver setup.', [], 409);
                }
                $r->update(['approver_user_id' => $candidates->first()->id]);
                $this->requests->log($r, $actor->id, 'approve', $step, 'pending', 'pending', $comment, null, array_filter(['on_behalf_of_user_id' => $onBehalf, 'next_step' => $next]));
                $this->support->completeTasks('leave:'.$r->uuid);
                $this->requests->notifyApprovers($r, $employee, $type, $next);
                $this->support->notify($this->approvers->userIdOf($employee), 'Leave step approved', "Step $step of {$r->total_steps} was approved; waiting for the next approver.", "leave:{$r->uuid}:step:$step", '/leave/my');
                Audit::record('step_approved', 'leave', 'request:'.$r->uuid, ['step' => $step]);

                return $r->refresh();
            }
            $parent = $r->parent_request_id ? LeaveRequest::query()->whereKey($r->parent_request_id)->lockForUpdate()->first() : null;
            $dates = [];
            if ($parent && in_array($parent->status, RequestService::OPEN, true)) {
                // the modification replaces the original: reverse it first so the balance never counts both
                foreach (LeaveRequestSegment::query()->where('request_id', $parent->id)->where('status', 'active')->get() as $old) {
                    if ($old->charged) {
                        $this->ledger->release($parent, $old, $actor->id, 'Replaced by modification '.$r->uuid);
                        $dates[] = $old->leave_date;
                    }
                    $old->update(['status' => 'cancelled']);
                }
                $from = $parent->status;
                $parent->update(['status' => 'modified', 'charged_units' => '0.0000', 'decided_at' => now()]);
                $this->requests->log($parent, $actor->id, 'modify', null, $from, 'modified', 'Replaced by '.$r->uuid);
            }
            foreach (LeaveRequestSegment::query()->where('request_id', $r->id)->where('status', 'active')->where('charged', true)->orderBy('leave_date')->get() as $segment) {
                $this->ledger->commit($r, $segment, $actor->id);
                $dates[] = $segment->leave_date;
            }
            $r->update(['status' => 'approved', 'decided_at' => now(), 'decided_by' => $actor->id, 'confidence' => 'confirmed', 'attendance_synced_at' => now()]);
            $this->requests->log($r, $actor->id, 'approve', $step, 'pending', 'approved', $comment, null, array_filter(['on_behalf_of_user_id' => $onBehalf]));
            $this->expectReturn($r, $employee, $type);
            $this->support->completeTasks('leave:'.$r->uuid);
            $this->support->coverageChanged($employee, $dates, $r->uuid, $actor->id, 'approved');
            $this->support->notify($this->approvers->userIdOf($employee), 'Leave approved', $this->label($type).' from '.$r->start_date.($r->end_date !== $r->start_date ? ' to '.$r->end_date : '').' was approved.', "leave:{$r->uuid}:approved", '/leave/my');
            if ($r->delegate_employee_id && ($delegate = Employee::query()->find($r->delegate_employee_id))) {
                $name = $employee->person?->display_name ?? 'A colleague';
                $this->support->notify($this->approvers->userIdOf($delegate), 'You are covering for a colleague', "$name will be away from {$r->start_date} to {$r->end_date}; you are their delegate.".($r->handover_notes ? ' Handover notes are on the request.' : ''), "leave:{$r->uuid}:delegate", '/leave/team');
            }
            $this->events->record(LeaveEvents::REQUEST_APPROVED, ['employee' => $employee, 'subject' => ['leave_request', $r->id], 'summary' => 'Leave approved', 'actor_id' => $actor->id,
                'payload' => ['uuid' => $r->uuid, 'start_date' => $r->start_date, 'end_date' => $r->end_date, 'units' => Units::normalize($r->getRawOriginal('charged_units'))], 'effective_date' => $r->start_date]);
            Audit::record('approved', 'leave', 'request:'.$r->uuid, ['on_behalf_of' => $onBehalf]);

            return $r->refresh();
        });
    }

    public function reject(User $actor, LeaveRequest $request, ?string $comment): LeaveRequest
    {
        $comment = $comment !== null ? trim($comment) : null;
        if ($comment === null || $comment === '') {
            throw ValidationException::withMessages(['comment' => 'Tell the employee why the leave is being rejected.']);
        }

        return DB::transaction(function () use ($actor, $request, $comment) {
            [$r, $employee, $type] = $this->open($actor, $request);
            $step = (int) $r->current_step;
            $this->requests->releaseAll($r, $actor->id, 'Rejected');
            $r->update(['status' => 'rejected', 'decided_at' => now(), 'decided_by' => $actor->id, 'charged_units' => '0.0000']);
            $this->requests->log($r, $actor->id, 'reject', $step, 'pending', 'rejected', $comment, null, array_filter(['on_behalf_of_user_id' => $this->approvers->onBehalfOf($actor, $r, $employee)]));
            $this->support->completeTasks('leave:'.$r->uuid);
            $this->support->notify($this->approvers->userIdOf($employee), 'Leave rejected', $this->label($type).' from '.$r->start_date.' was rejected: '.$comment, "leave:{$r->uuid}:rejected", '/leave/my');
            $this->events->record(LeaveEvents::REQUEST_REJECTED, ['employee' => $employee, 'subject' => ['leave_request', $r->id], 'summary' => 'Leave rejected', 'actor_id' => $actor->id, 'payload' => ['uuid' => $r->uuid], 'effective_date' => $r->start_date]);
            Audit::record('rejected', 'leave', 'request:'.$r->uuid);

            return $r->refresh();
        });
    }

    /**
     * Conditional escalation: a pending request that has waited longer than approvals.escalate_after_hours (policy or tenant
     * setting) is also routed to HR (who can then decide it), the requester and approver are told, and the history records why.
     * Idempotent. Returns true when the request was escalated now.
     */
    public function escalateIfOverdue(LeaveRequest $request, ?int $hours = null): bool
    {
        return DB::transaction(function () use ($request, $hours) {
            $r = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending' || ! empty($r->explanation['approval']['escalated'])) {
                return false;
            }
            $hours ??= (int) (\App\Domain\Leave\Duration\RuleSet::load($r->employee_id, $r->leave_type_id, $r->start_date, $r->policy_version_id)->rules['approvals']['escalate_after_hours'] ?? LeaveSettings::get('approvals.escalate_after_hours', 48));
            $since = $this->lastMovement($r);
            if ($hours <= 0 || $since->addHours($hours)->isFuture()) {
                return false;
            }
            $employee = Employee::query()->findOrFail($r->employee_id);
            $type = LeaveType::query()->findOrFail($r->leave_type_id);
            $explanation = $r->explanation;
            $explanation['approval']['escalated'] = true;
            $explanation['approval']['escalated_at'] = now()->toIso8601String();
            $r->update(['explanation' => $explanation]);
            $hr = $this->approvers->forRole($employee, $r->start_date, 'hr', $r->requested_by && $r->requested_by !== $this->approvers->userIdOf($employee) ? [(int) $r->requested_by] : []);
            $this->requests->log($r, null, 'escalate', (int) $r->current_step, 'pending', 'pending', "No decision for $hours hour(s); escalated to HR.");
            $name = $employee->person?->display_name ?? $employee->employee_number;
            $this->support->assignTasks($hr, 'Escalated leave approval: '.$name.', '.$r->start_date, "Waiting more than $hours hour(s) for a decision.", 'leave:'.$r->uuid, '/leave/approvals?request='.$r->uuid, TenantClock::today(), ['kind' => 'leave', 'uuid' => $r->uuid, 'escalated' => true]);
            $this->support->notify($r->approver_user_id, 'Leave approval is overdue', 'A leave request has been waiting for you and was escalated to HR.', "leave:{$r->uuid}:escalated:approver", '/leave/approvals');
            $this->support->notify($this->approvers->userIdOf($employee), 'Your leave request was escalated', 'It had no decision for a while, so HR was also asked.', "leave:{$r->uuid}:escalated:employee", '/leave/my');
            Audit::record('escalated', 'leave', 'request:'.$r->uuid, ['after_hours' => $hours]);

            return true;
        });
    }

    // ---- internals --------------------------------------------------------------------------------------------

    /** @return array{0:LeaveRequest,1:Employee,2:LeaveType} the locked pending request, after the decision rights check */
    private function open(User $actor, LeaveRequest $request): array
    {
        $r = LeaveRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
        abort_unless($r->status === 'pending', 409, "This request was already {$r->status}.");
        $employee = Employee::query()->findOrFail($r->employee_id);
        abort_unless($this->approvers->canDecide($actor, $r, $employee), 403, 'You are not an approver for this leave request.');

        return [$r, $employee, LeaveType::query()->findOrFail($r->leave_type_id)];
    }

    private function label(LeaveType $type): string
    {
        return $type->is_sensitive ? 'Your leave' : $type->name;
    }

    private function lastMovement(LeaveRequest $r): CarbonImmutable
    {
        $last = $r->actions()->whereIn('action', ['submit', 'approve', 'escalate'])->max('created_at');

        return CarbonImmutable::parse($last ?? $r->submitted_at ?? $r->created_at);
    }

    /** Expected return-to-work date: the first working day after the last leave day (schedule-aware, weekdays as the fallback). */
    private function expectReturn(LeaveRequest $r, Employee $employee, LeaveType $type): void
    {
        $long = in_array($type->kind, ['maternity', 'paternity', 'parental', 'medical', 'sabbatical', 'study'], true) || Units::cmp($r->getRawOriginal('charged_units'), '5') >= 0 || ! empty($type->config['return_to_work']);
        if (! $long || LeaveReturnToWork::query()->where('request_id', $r->id)->exists()) {
            return;
        }
        $from = CarbonImmutable::parse($r->end_date)->addDay();
        $days = $this->schedules->resolveRange($employee->id, $from->toDateString(), $from->addDays(14)->toDateString());
        $return = null;
        foreach ($days as $date => $day) {
            if ($day->kind === 'working') {
                $return = $date;
                break;
            }
        }
        $return ??= (function () use ($from) {
            $d = $from;
            while ($d->isWeekend()) {
                $d = $d->addDay();
            }

            return $d->toDateString();
        })();
        LeaveReturnToWork::create(['request_id' => $r->id, 'employee_id' => $employee->id, 'expected_return_date' => $return, 'status' => 'expected']);
    }
}
