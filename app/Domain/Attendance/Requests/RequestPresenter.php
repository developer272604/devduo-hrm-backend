<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Attendance\Models\ApprovalDelegation;
use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Attendance\Models\OnDutyRequest;
use App\Domain\Attendance\Models\OvertimeRequest;
use App\Domain\Attendance\Models\Regularization;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** API shapes for requests. Only public UUIDs leave the API; user ids are replaced by names. */
final class RequestPresenter
{
    private array $names = [];

    public function __construct(private ApproverResolver $approvers) {}

    public function employee(?Employee $e): ?array
    {
        if (! $e) {
            return null;
        }
        $e->loadMissing('person');

        return ['uuid' => $e->uuid, 'name' => $e->person?->display_name, 'employee_number' => $e->employee_number];
    }

    public function userName(?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return $this->names[$id] ??= User::query()->whereKey($id)->value('name');
    }

    public function regularization(Regularization $r, User $viewer, bool $detail = false): array
    {
        $r->loadMissing('employee.person');
        $pending = $r->status === 'pending';
        $out = ['uuid' => $r->uuid, 'kind' => 'regularization', 'employee' => $this->employee($r->employee), 'date' => $r->attendance_date, 'type' => $r->type, 'type_label' => RegularizationService::TYPES[$r->type] ?? $r->type,
            'requested_in' => RequestSupport::iso($r->requested_in), 'requested_out' => RequestSupport::iso($r->requested_out), 'reason' => $r->reason, 'comments' => $r->comments, 'status' => $r->status,
            'current_step' => (int) $r->current_step, 'total_steps' => (int) $r->total_steps, 'has_attachment' => (bool) $r->attachment_path, 'submitted_by' => $this->userName($r->submitted_by),
            'submitted_at' => $r->created_at?->toIso8601String(), 'decided_at' => $r->decided_at?->toIso8601String(),
            'current_approver' => $pending ? $this->userName($this->approvers->forEmployee($r->employee, 'regularization', $r->attendance_date, (int) $r->current_step)?->id) : null,
            'can_decide' => $pending && $this->approvers->canDecide($viewer, $r->employee, 'regularization', $r->attendance_date, (int) $r->current_step),
            'can_withdraw' => $pending && ($r->submitted_by === $viewer->id)];
        if ($detail) {
            $out['current_snapshot'] = $r->current_snapshot;
            $out['steps'] = $r->actions()->get()->map(fn ($a) => ['step' => (int) $a->step, 'action' => $a->action, 'actor' => $this->userName($a->actor_id), 'on_behalf_of' => $this->userName($a->on_behalf_of),
                'comment' => $a->comment, 'at' => $a->acted_at?->toIso8601String()])->all();
        }

        return $out;
    }

    public function overtime(OvertimeRequest $r, User $viewer): array
    {
        $r->loadMissing('employee.person');
        $pending = $r->status === 'pending';
        $exclude = $r->initiated_by === 'manager' ? [$r->requested_by] : [];

        return ['uuid' => $r->uuid, 'kind' => 'overtime', 'employee' => $this->employee($r->employee), 'date' => $r->work_date, 'mode' => $r->mode, 'initiated_by' => $r->initiated_by, 'ot_type' => $r->ot_type,
            'requested_minutes' => (int) $r->requested_minutes, 'calculated_minutes' => $r->calculated_minutes === null ? null : (int) $r->calculated_minutes, 'approved_minutes' => $r->approved_minutes === null ? null : (int) $r->approved_minutes,
            'reason' => $r->reason, 'status' => $r->status, 'requested_by' => $this->userName($r->requested_by), 'decided_by' => $this->userName($r->decided_by), 'decision_comment' => $r->decision_comment,
            'submitted_at' => $r->created_at?->toIso8601String(), 'decided_at' => $r->decided_at?->toIso8601String(),
            'current_approver' => $pending ? $this->userName($this->approvers->forEmployee($r->employee, 'overtime', $r->work_date, 1, $exclude)?->id) : null,
            'can_decide' => $pending && $this->approvers->canDecide($viewer, $r->employee, 'overtime', $r->work_date, 1, $exclude), 'can_withdraw' => $pending && $r->requested_by === $viewer->id];
    }

    public function onDuty(OnDutyRequest $r, User $viewer): array
    {
        $r->loadMissing('employee.person');
        $pending = $r->status === 'pending';
        $exclude = $r->requested_by !== $this->approvers->userIdOf($r->employee) ? [$r->requested_by] : [];

        return ['uuid' => $r->uuid, 'kind' => 'on_duty', 'employee' => $this->employee($r->employee), 'type' => $r->type, 'type_label' => OnDutyService::TYPES[$r->type] ?? $r->type, 'is_remote' => $r->type === 'remote_work',
            'start_date' => $r->start_date, 'end_date' => $r->end_date, 'date' => $r->start_date, 'full_day' => (bool) $r->full_day, 'starts_at' => RequestSupport::iso($r->starts_at), 'ends_at' => RequestSupport::iso($r->ends_at), 'timezone' => $r->timezone,
            'reason' => $r->reason, 'location_text' => $r->location_text, 'has_attachment' => (bool) $r->attachment_path, 'status' => $r->status, 'requested_by' => $this->userName($r->requested_by), 'decided_by' => $this->userName($r->decided_by),
            'decision_comment' => $r->decision_comment, 'submitted_at' => $r->created_at?->toIso8601String(), 'decided_at' => $r->decided_at?->toIso8601String(),
            'current_approver' => $pending ? $this->userName($this->approvers->forEmployee($r->employee, 'on_duty', $r->start_date, 1, $exclude)?->id) : null,
            'can_decide' => $pending && $this->approvers->canDecide($viewer, $r->employee, 'on_duty', $r->start_date, 1, $exclude), 'can_withdraw' => $pending && $r->requested_by === $viewer->id];
    }

    public function adjustment(AttendanceAdjustment $a, User $viewer): array
    {
        $a->loadMissing('employee.person');

        return ['uuid' => $a->uuid, 'kind' => 'adjustment', 'employee' => $this->employee($a->employee), 'date' => $a->attendance_date, 'type' => $a->type, 'before' => $a->before, 'after' => $a->after, 'reason' => $a->reason,
            'status' => $a->status, 'requires_checker' => (bool) $a->requires_checker, 'maker' => $this->userName($a->maker_id), 'checker' => $this->userName($a->checker_id), 'checked_at' => $a->checked_at?->toIso8601String(),
            'checker_comment' => $a->checker_comment, 'applied_at' => $a->applied_at?->toIso8601String(), 'batch' => $a->batch_uuid, 'created_at' => $a->created_at?->toIso8601String(),
            'can_decide' => $a->status === 'pending_approval' && $a->maker_id !== $viewer->id && $viewer->allows('attendance.adjust.approve'),
            'can_revert' => in_array($a->status, ['applied', 'approved'], true) && $a->type !== 'reversal' && $viewer->allows('attendance.adjust')];
    }

    public function delegation(ApprovalDelegation $d): array
    {
        return ['uuid' => $d->uuid, 'delegator' => $this->person($d->delegator_user_id), 'delegate' => $this->person($d->delegate_user_id), 'starts_on' => $d->starts_on, 'ends_on' => $d->ends_on,
            'kinds' => $d->kinds, 'reason' => $d->reason, 'active' => (bool) $d->active, 'in_effect' => $d->active && $d->starts_on <= \App\Domain\Tenancy\TenantClock::today() && $d->ends_on >= \App\Domain\Tenancy\TenantClock::today()];
    }

    /** @return array{employee:?string,name:?string} the employment (public id) behind a login */
    private function person(int $userId): array
    {
        $row = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('people.user_id', $userId)->where('employees.tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)
            ->whereNull('employees.deleted_at')->orderBy('employees.id')->first(['employees.uuid', 'people.display_name']);

        return ['employee' => $row?->uuid, 'name' => $row->display_name ?? $this->userName($userId)];
    }
}
