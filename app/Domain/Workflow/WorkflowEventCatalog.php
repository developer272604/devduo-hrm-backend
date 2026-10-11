<?php

namespace App\Domain\Workflow;

/** Employee-linked source events safe to use as workflow start signals. */
final class WorkflowEventCatalog
{
    public const EVENTS = [
        'EmployeeCreated' => 'Employee created',
        'EmployeeActivated' => 'Employee activated',
        'EmploymentStatusChanged' => 'Employment status changed',
        'EmployeeSeparated' => 'Employee separated',
        'EmployeeRehired' => 'Employee rehired',
        'EmployeeTransferred' => 'Employee transferred',
        'EmployeePromoted' => 'Employee promoted',
        'ProbationDue' => 'Probation review due',
        'DocumentExpiring' => 'Employee document expiring',
        'RecruitmentHired' => 'Recruitment hire handed over',
        'leave.request.submitted' => 'Leave request submitted',
        'RegularizationSubmitted' => 'Attendance regularization submitted',
        'OvertimeRequested' => 'Overtime requested',
        'OnDutyRequested' => 'On-duty request submitted',
        'AttendanceExceptionDetected' => 'Attendance exception detected',
        'PerformanceReviewFinalized' => 'Performance review finalized',
    ];

    public const EMPLOYMENT_STATUSES = [
        'preboarding', 'active', 'probation', 'notice_period', 'suspended', 'long_leave', 'inactive', 'separated', 'archived',
    ];

    public static function options(): array
    {
        return collect(self::EVENTS)->map(fn ($label, $type) => ['value' => $type, 'label' => $label])->values()->all();
    }
}
