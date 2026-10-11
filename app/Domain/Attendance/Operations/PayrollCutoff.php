<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Attendance\Support\AttendanceSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Payroll cutoff arithmetic, deterministic and explicit.
 *
 * Convention (documented in docs/api/attendance-operations.md): the cutoff day D is the day of the month on which payroll
 * for the PREVIOUS calendar month is cut; that month's attendance must be finalized (approved or locked) by the end of that day.
 * A payroll group's own cutoff_day wins over the tenant setting cutoffs.payroll_cutoff_day. Without a cutoff day nothing is "at risk".
 */
final class PayrollCutoff
{
    /** Days before the cutoff on which unfinished attendance is reported as a risk (action required, never critical). */
    public const WARNING_DAYS = 3;

    public static function day(?int $payrollGroupId = null): ?int
    {
        if ($payrollGroupId) {
            $own = DB::table('payroll_groups')->where('tenant_id', Ops::tenantId())->where('id', $payrollGroupId)->value('cutoff_day');
            if ($own) {
                return (int) $own;
            }
        }
        $day = AttendanceSettings::get('cutoffs.payroll_cutoff_day');

        return $day ? (int) $day : null;
    }

    /**
     * @return array{configured:bool,period_from:?string,period_to:?string,cutoff_date:?string,days_to_cutoff:?int,status:string}
     *                                                                                                                              status: not_configured | upcoming | at_risk_window | passed
     */
    public static function state(string $today, ?int $day): array
    {
        if (! $day) {
            return ['configured' => false, 'period_from' => null, 'period_to' => null, 'cutoff_date' => null, 'days_to_cutoff' => null, 'status' => 'not_configured'];
        }
        $now = CarbonImmutable::parse($today);
        $cutoff = $now->startOfMonth()->addDays(min($day, $now->daysInMonth) - 1);
        $period = $now->startOfMonth()->subMonthNoOverflow();
        $days = (int) $now->diffInDays($cutoff, false);
        $status = $days < 0 ? 'passed' : ($days <= self::WARNING_DAYS ? 'at_risk_window' : 'upcoming');

        return ['configured' => true, 'period_from' => $period->toDateString(), 'period_to' => $period->endOfMonth()->toDateString(), 'cutoff_date' => $cutoff->toDateString(), 'days_to_cutoff' => $days, 'status' => $status];
    }

    /**
     * Employee-days of the period that are not finalized (finalization = open) or still carry blocking open exceptions.
     * $employeeIds null means the whole tenant.
     *
     * @return array{open_days:int,employees:int,blocking_exceptions:int}
     */
    public static function unfinalized(string $from, string $to, ?array $employeeIds = null): array
    {
        $tenant = Ops::tenantId();
        $days = DB::table('attendance_days')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->where('finalization', 'open');
        $blocking = DB::table('attendance_exceptions')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->whereIn('status', ['open', 'acknowledged'])->whereIn('severity', ['action_required', 'critical']);
        if ($employeeIds !== null) {
            $days->whereIn('employee_id', $employeeIds ?: [0]);
            $blocking->whereIn('employee_id', $employeeIds ?: [0]);
        }

        return ['open_days' => (clone $days)->count(), 'employees' => (clone $days)->distinct()->count('employee_id'), 'blocking_exceptions' => $blocking->count()];
    }
}
