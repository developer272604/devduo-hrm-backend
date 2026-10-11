<?php

namespace App\Domain\People\Services;

use App\Domain\Access\Classification;
use App\Domain\Access\PeopleSettings;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use Illuminate\Support\Facades\DB;

/**
 * Standardized employment lifecycle. States are data, not business rules: what each state *means* for
 * attendance, leave or payroll is decided by those modules from the status code. Valid transitions have
 * defaults and can be overridden per tenant under settings.people.statuses.transitions.
 */
final class StatusService
{
    public const TRANSITIONS = [
        'preboarding' => ['active', 'probation', 'inactive'],
        'active' => ['probation', 'notice_period', 'suspended', 'long_leave', 'inactive', 'separated'],
        'probation' => ['active', 'notice_period', 'suspended', 'long_leave', 'inactive', 'separated'],
        'notice_period' => ['active', 'probation', 'suspended', 'separated'],
        'suspended' => ['active', 'probation', 'notice_period', 'separated'],
        'long_leave' => ['active', 'probation', 'separated'],
        'inactive' => ['active', 'probation', 'separated', 'archived'],
        'separated' => ['archived'],
        'archived' => [],
    ];

    public const LABELS = ['preboarding' => 'Preboarding', 'active' => 'Active', 'probation' => 'Probation', 'notice_period' => 'Notice period', 'suspended' => 'Suspended',
        'long_leave' => 'Long leave', 'separated' => 'Separated', 'inactive' => 'Inactive', 'archived' => 'Archived'];

    public function __construct(private DomainEvents $events) {}

    public function allowed(string $from): array
    {
        return PeopleSettings::get('statuses.transitions.'.$from, self::TRANSITIONS[$from] ?? []);
    }

    /** @param array{reason_code?:?string,reason?:?string,effective_date?:?string} $opts */
    public function transition(Employee $employee, string $to, array $opts = []): Employee
    {
        abort_unless(isset(self::LABELS[$to]), 422, 'Unknown employment status.');

        return DB::transaction(function () use ($employee, $to, $opts) {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $from = $employee->status;
            abort_if($from === $to, 422, 'The employee is already '.strtolower(self::LABELS[$to]).'.');
            abort_unless(in_array($to, $this->allowed($from), true), 422, 'An employee cannot move from '.strtolower(self::LABELS[$from]).' to '.strtolower(self::LABELS[$to]).'.');
            $date = $opts['effective_date'] ?? TenantClock::today($employee->timezone);

            $employee->status = $to;
            if ($to === 'separated') {
                $employee->separated_on ??= $date;
            }
            if ($to === 'archived') {
                $employee->archived_at = now();
            }
            if ($from === 'preboarding' && ! $employee->employment_start_date) {
                $employee->employment_start_date = $employee->joined_on;
            }
            $employee->save();

            DB::table('employee_status_history')->insert(['tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id, 'from_status' => $from, 'to_status' => $to,
                'reason_code' => $opts['reason_code'] ?? null, 'reason' => $opts['reason'] ?? null, 'effective_date' => $date, 'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $this->events->record('EmploymentStatusChanged', ['employee' => $employee, 'subject' => ['employee', $employee->id], 'effective_date' => $date, 'classification' => Classification::PUBLIC_INTERNAL,
                'summary' => 'Employment status changed from '.self::LABELS[$from].' to '.self::LABELS[$to].'.', 'payload' => ['from' => $from, 'to' => $to, 'reason_code' => $opts['reason_code'] ?? null, 'reason' => $opts['reason'] ?? null]]);
            if ($from === 'preboarding' && in_array($to, ['active', 'probation'], true)) {
                $this->events->record('EmployeeActivated', ['employee' => $employee, 'subject' => ['employee', $employee->id], 'effective_date' => $date, 'classification' => Classification::PUBLIC_INTERNAL, 'summary' => 'Employee activated.']);
            }
            Audit::record('status_changed', 'people', 'employee:'.$employee->uuid, ['from' => $from, 'to' => $to]);

            return $employee;
        });
    }

    public function history(Employee $employee): array
    {
        return DB::table('employee_status_history')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->orderByDesc('effective_date')->orderByDesc('id')
            ->get(['from_status', 'to_status', 'reason_code', 'reason', 'effective_date', 'changed_by', 'created_at'])->all();
    }
}
