<?php

namespace App\Domain\Intelligence;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Employee Time Story: a dated list of FACTS about one employment (joining, probation, status changes, approved leave spans, approved
 * overtime). Shown only when the employee is inside the viewer's data scope. Leave type, reason and medical flags are never included.
 */
final class TimeStoryService
{
    public function story(User $viewer, string $employeeUuid): array
    {
        $employee = Employee::where('uuid', $employeeUuid)->first();
        abort_unless($employee && app(DataScope::class)->canAccess($viewer, $employee, 'intelligence.view'), 404);
        $t = $viewer->tenant_id;
        $e = [];
        $d = fn ($v) => substr((string) $v, 0, 10);
        $e[] = ['date' => $d($employee->joined_on), 'kind' => 'joined', 'label' => 'Joined', 'source' => 'people'];
        if ($employee->probation_start) {
            $e[] = ['date' => $d($employee->probation_start), 'kind' => 'probation_start', 'label' => 'Probation started', 'source' => 'people'];
        }
        if ($employee->probation_end && ! $employee->confirmation_date) {
            $e[] = ['date' => $d($employee->probation_end), 'kind' => 'probation_end', 'label' => 'Probation expected to end'.((int) $employee->probation_extensions ? ' (extended '.(int) $employee->probation_extensions.'x)' : ''), 'source' => 'people'];
        }
        if ($employee->confirmation_date) {
            $e[] = ['date' => $d($employee->confirmation_date), 'kind' => 'confirmed', 'label' => 'Confirmed', 'source' => 'people'];
        }
        foreach (DB::table('employee_status_history')->where('tenant_id', $t)->where('employee_id', $employee->id)->get(['from_status', 'to_status', 'effective_date']) as $h) {
            $e[] = ['date' => $d($h->effective_date), 'kind' => 'status', 'label' => 'Status '.($h->from_status ? $h->from_status.' to ' : 'set to ').$h->to_status, 'source' => 'people'];
        }
        foreach (DB::table('leave_requests')->where('tenant_id', $t)->where('employee_id', $employee->id)->where('status', 'approved')->where('is_sensitive', false)->get(['start_date', 'end_date', 'charged_units']) as $l) {
            $e[] = ['date' => $d($l->start_date), 'kind' => 'leave', 'label' => 'Approved leave until '.$d($l->end_date), 'source' => 'leave'];
        }
        foreach (DB::table('overtime_requests')->where('tenant_id', $t)->where('employee_id', $employee->id)->whereIn('status', ['approved', 'partially_approved'])->get(['work_date', 'approved_minutes']) as $o) {
            $e[] = ['date' => $d($o->work_date), 'kind' => 'overtime', 'label' => 'Approved overtime '.(int) $o->approved_minutes.' min', 'source' => 'attendance'];
        }
        usort($e, fn ($a, $b) => [$b['date'], $a['kind']] <=> [$a['date'], $b['kind']]);
        Audit::record('intelligence.time_story.viewed', 'intelligence', 'employee:'.$employee->uuid);

        return ['employee_uuid' => $employee->uuid, 'events' => $e, 'notice' => 'Facts with their source module. Leave types and reasons are not shown. No conclusions are drawn.'];
    }
}
