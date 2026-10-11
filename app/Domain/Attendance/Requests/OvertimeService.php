<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Attendance\Models\OvertimeRequest;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Overtime requests: pre-approval (before the work) and post-work (after, against the engine's calculated minutes),
 * employee- or manager-initiated. calculated_minutes (engine) and approved_minutes (human decision) are stored separately.
 * A manager-initiated request is never decided by the manager who raised it (two-person control): it routes to HR.
 */
final class OvertimeService
{
    public const MODES = ['pre_approval', 'post_work'];

    public const TYPES = ['pre_shift', 'post_shift', 'weekly_off', 'holiday', 'rest_day'];

    public function __construct(private RequestSupport $support, private ApproverResolver $approvers, private DomainEvents $events) {}

    /** @return array{0:OvertimeRequest,1:bool} */
    public function submit(User $actor, Employee $employee, array $data, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && ($existing = OvertimeRequest::query()->where('idempotency_key', $idempotencyKey)->first())) {
            abort_unless($existing->employee_id === $employee->id, 409, 'That idempotency key was already used for a different request.');

            return [$existing, false];
        }
        $date = $data['work_date'] ?? null;
        $mode = $data['mode'] ?? null;
        $type = $data['ot_type'] ?? 'post_shift';
        $minutes = $data['requested_minutes'] ?? null;
        $reason = trim((string) ($data['reason'] ?? ''));
        $errors = [];
        if (! LocalInstant::validDate($date)) {
            $errors['work_date'] = 'Choose the work date (YYYY-MM-DD).';
        }
        if (! in_array($mode, self::MODES, true)) {
            $errors['mode'] = 'Mode must be pre_approval (before the work) or post_work (after the work).';
        }
        if (! in_array($type, self::TYPES, true)) {
            $errors['ot_type'] = 'Overtime type must be one of: '.implode(', ', self::TYPES).'.';
        }
        if (! is_numeric($minutes) || (int) $minutes < 1 || (int) $minutes > 1440) {
            $errors['requested_minutes'] = 'Enter the overtime in minutes (1 to 1440).';
        }
        if ($reason === '' || mb_strlen($reason) > 2000) {
            $errors['reason'] = 'Give a short reason (up to 2000 characters).';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $minutes = (int) $minutes;
        $initiatedBy = $this->support->isSelf($actor, $employee) ? 'employee' : 'manager';
        abort_unless($initiatedBy === 'employee' || $this->support->scope()->canAccess($actor, $employee, 'attendance.overtime.approve'), 404, 'That employee was not found.');

        $rules = RequestRules::overtime($employee, $date);
        abort_unless($rules['enabled'] ?? true, 422, 'Overtime is not enabled for this employee\'s attendance policy.');
        if (! ($rules[$type] ?? true)) {
            throw ValidationException::withMessages(['ot_type' => 'The attendance policy does not allow '.str_replace('_', ' ', $type).' overtime.']);
        }
        if (! empty($rules['eligible_employee_type_ids']) && ! in_array($employee->assignmentOn($date)?->employee_type_id, (array) $rules['eligible_employee_type_ids'], true)) {
            throw ValidationException::withMessages(['work_date' => 'This employee type is not eligible for overtime.']);
        }
        if (! empty($rules['maximum_minutes_per_day']) && $minutes > (int) $rules['maximum_minutes_per_day']) {
            throw ValidationException::withMessages(['requested_minutes' => "Overtime is limited to {$rules['maximum_minutes_per_day']} minutes per day."]);
        }
        $today = RequestSupport::today($employee);
        if ($mode === 'pre_approval' && $date < $today) {
            throw ValidationException::withMessages(['work_date' => 'Pre-approval is for today or a future date. Use post-work overtime for days already worked.']);
        }
        if ($mode === 'post_work') {
            $window = (int) RequestRules::setting('cutoffs.overtime_window_days', 31);
            if ($date > $today) {
                throw ValidationException::withMessages(['work_date' => 'Post-work overtime cannot be requested for a future date.']);
            }
            if ($date < CarbonImmutable::parse($today)->subDays($window)->toDateString()) {
                throw ValidationException::withMessages(['work_date' => "Post-work overtime must be requested within $window days of the work date."]);
            }
        }
        if (OvertimeRequest::query()->where('employee_id', $employee->id)->where('work_date', $date)->where('mode', $mode)->whereIn('status', ['pending', 'approved', 'partially_approved'])->exists()) {
            throw ValidationException::withMessages(['work_date' => 'An overtime request for this date already exists.']);
        }
        $this->support->assertUnlocked($employee->id, $date);
        $exclude = $initiatedBy === 'manager' ? [$actor->id] : [];
        $approver = $this->approvers->forEmployee($employee, 'overtime', $date, 1, $exclude);
        abort_unless($approver, 422, 'No approver is available for this overtime request. Please ask HR to assign a reporting manager.');

        return [DB::transaction(function () use ($actor, $employee, $date, $mode, $type, $minutes, $reason, $initiatedBy, $idempotencyKey, $approver, $exclude) {
            $r = OvertimeRequest::create(['employee_id' => $employee->id, 'work_date' => $date, 'mode' => $mode, 'initiated_by' => $initiatedBy, 'ot_type' => $type, 'requested_minutes' => $minutes,
                'calculated_minutes' => $this->calculated($employee->id, $date), 'reason' => $reason, 'status' => 'pending', 'requested_by' => $actor->id, 'approver_user_id' => $approver->id, 'idempotency_key' => $idempotencyKey]);
            $name = $employee->person?->display_name ?? $employee->employee_number;
            $due = CarbonImmutable::parse(RequestSupport::today($employee))->addDays((int) RequestRules::setting('cutoffs.manager_approval_days', 2))->toDateString();
            $this->support->assignTasks($this->approvers->candidates($employee, 'overtime', $date, 1, $exclude), "Approve overtime: $name, $date ($minutes min)", ucfirst(str_replace('_', ' ', $mode)).': '.$reason,
                'overtime:'.$r->uuid, '/time-office/approvals?kind=overtime', $due, ['kind' => 'overtime', 'uuid' => $r->uuid]);
            Audit::record('requested', 'attendance', 'overtime:'.$r->uuid, ['employee' => $employee->uuid, 'date' => $date, 'mode' => $mode, 'minutes' => $minutes, 'initiated_by' => $initiatedBy]);
            $this->events->record('OvertimeRequested', ['employee' => $employee, 'subject' => ['overtime_request', $r->id], 'summary' => "Overtime of $minutes minutes requested for $date",
                'payload' => ['uuid' => $r->uuid, 'date' => $date, 'mode' => $mode, 'requested_minutes' => $minutes, 'initiated_by' => $initiatedBy], 'effective_date' => $date]);

            return $r->load('employee');
        }), true];
    }

    /** approve (optionally partially, via $approvedMinutes) or reject. */
    public function decide(User $actor, OvertimeRequest $request, string $decision, ?int $approvedMinutes, ?string $comment): OvertimeRequest
    {
        abort_unless(in_array($decision, ['approve', 'reject'], true), 422, 'Decision must be approve or reject.');
        $comment = $comment !== null ? trim($comment) : null;
        if ($decision === 'reject' && ($comment === null || $comment === '')) {
            throw ValidationException::withMessages(['comment' => 'Tell the requester why the overtime is being rejected.']);
        }

        return DB::transaction(function () use ($actor, $request, $decision, $approvedMinutes, $comment) {
            $r = OvertimeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->status === 'pending', 409, "This request was already {$r->status}.");
            $employee = Employee::query()->findOrFail($r->employee_id);
            $exclude = $r->initiated_by === 'manager' ? [$r->requested_by] : [];
            abort_unless($this->approvers->canDecide($actor, $employee, 'overtime', $r->work_date, 1, $exclude), 403, 'You are not an approver for this overtime request.');
            $entity = 'overtime:'.$r->uuid;
            $recipients = array_unique(array_filter([$this->approvers->userIdOf($employee), $r->requested_by]));

            if ($decision === 'reject') {
                $r->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment]);
                $this->support->completeTasks($entity);
                foreach ($recipients as $uid) {
                    $this->support->notify($uid, 'Overtime rejected', "Overtime for {$r->work_date} was rejected: $comment", "ot:{$r->uuid}:rejected", '/attendance?tab=overtime');
                }
                $this->events->record('OvertimeRejected', ['employee' => $employee, 'subject' => ['overtime_request', $r->id], 'summary' => "Overtime for {$r->work_date} rejected", 'payload' => ['uuid' => $r->uuid], 'effective_date' => $r->work_date]);
                Audit::record('rejected', 'attendance', $entity, ['comment' => $comment]);

                return $r->refresh();
            }

            $calculated = $this->calculated($employee->id, $r->work_date);
            $approved = $approvedMinutes ?? (int) $r->requested_minutes;
            if ($approved < 1 || $approved > (int) $r->requested_minutes) {
                throw ValidationException::withMessages(['approved_minutes' => "Approved minutes must be between 1 and the {$r->requested_minutes} requested."]);
            }
            if ($r->mode === 'post_work') {
                if (! $calculated) {
                    throw ValidationException::withMessages(['approved_minutes' => 'No overtime has been calculated for this day yet. Wait for attendance processing, or reject the request.']);
                }
                if ($approved > $calculated) {
                    throw ValidationException::withMessages(['approved_minutes' => "Approved overtime cannot exceed the $calculated minutes calculated for the day."]);
                }
            }
            $max = (int) (RequestRules::overtime($employee, $r->work_date)['maximum_minutes_per_day'] ?? 0);
            if ($max && $approved > $max) {
                throw ValidationException::withMessages(['approved_minutes' => "Overtime is limited to $max minutes per day."]);
            }
            $this->support->assertUnlocked($employee->id, $r->work_date);
            $status = $approved === (int) $r->requested_minutes ? 'approved' : 'partially_approved';
            $r->update(['status' => $status, 'approved_minutes' => $approved, 'calculated_minutes' => $calculated, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_comment' => $comment]);
            $this->support->completeTasks($entity);
            foreach ($recipients as $uid) {
                $this->support->notify($uid, 'Overtime approved', "Overtime for {$r->work_date}: $approved of {$r->requested_minutes} minutes approved.", "ot:{$r->uuid}:approved", '/attendance?tab=overtime');
            }
            $this->events->record('OvertimeApproved', ['employee' => $employee, 'subject' => ['overtime_request', $r->id], 'summary' => "Overtime for {$r->work_date} approved ($approved min)",
                'payload' => ['uuid' => $r->uuid, 'date' => $r->work_date, 'approved_minutes' => $approved, 'requested_minutes' => (int) $r->requested_minutes, 'status' => $status], 'effective_date' => $r->work_date]);
            $this->support->recalculate($employee->id, $r->work_date, 'regularization', $actor->id);
            Audit::record($status === 'approved' ? 'approved' : 'partially_approved', 'attendance', $entity, ['approved_minutes' => $approved, 'comment' => $comment]);

            return $r->refresh();
        });
    }

    public function withdraw(User $actor, OvertimeRequest $request): OvertimeRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            $r = OvertimeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($r->status === 'pending', 409, "This request was already {$r->status}.");
            abort_unless($r->requested_by === $actor->id || $this->support->isSelf($actor, $r->employee_id), 403, 'Only the person who raised the request can withdraw it.');
            $r->update(['status' => 'withdrawn', 'decided_at' => now()]);
            $this->support->completeTasks('overtime:'.$r->uuid);
            Audit::record('withdrawn', 'attendance', 'overtime:'.$r->uuid);

            return $r->refresh();
        });
    }

    /** Pending overtime past the cutoff is closed visibly (status cancelled) and the requester is told. */
    public function expire(OvertimeRequest $request): bool
    {
        return DB::transaction(function () use ($request) {
            $r = OvertimeRequest::query()->whereKey($request->id)->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending') {
                return false;
            }
            $r->update(['status' => 'cancelled', 'decided_at' => now(), 'decision_comment' => 'Closed automatically: no decision before the cutoff.']);
            $this->support->completeTasks('overtime:'.$r->uuid);
            $this->support->notify($r->requested_by, 'Overtime request closed', "Your overtime request for {$r->work_date} had no decision before the cutoff and was closed.", "ot:{$r->uuid}:expired", '/attendance?tab=overtime');
            Audit::record('expired', 'attendance', 'overtime:'.$r->uuid);

            return true;
        });
    }

    /** Engine-calculated overtime for the day (null when the day has not been calculated). */
    public function calculated(int $employeeId, string $date): ?int
    {
        $value = DB::table('attendance_days')->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('employee_id', $employeeId)->where('attendance_date', $date)->value('overtime_minutes');

        return $value === null ? null : (int) $value;
    }
}
