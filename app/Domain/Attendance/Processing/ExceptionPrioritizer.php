<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Attendance\Support\ExceptionType;
use App\Domain\Attendance\Support\Severity;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic, business-context severity (no scores, no disciplinary conclusions). Every decision carries a plain-language
 * reason that is stored in details.priority_reason.
 *
 *   critical         ONLY: a blocking issue on a day not finalized before the payroll cutoff
 *   action_required  blocks finalization: missing / unpaired punch, no shift, no policy
 *   warning          repeated late arrivals (allowance exceeded), location outside the permitted area, integrity review
 *   info             everything else, including recorded overtime
 */
final class ExceptionPrioritizer
{
    public const BLOCKING = [ExceptionType::MISSING_IN, ExceptionType::MISSING_OUT, ExceptionType::UNPAIRED_PUNCH, ExceptionType::NO_SHIFT, ExceptionType::NO_POLICY];

    /**
     * @param  array{severity?:?string,details?:array}  $detected  what the engine reported
     * @param  array{employee_id:int,date:string,finalization?:?string}  $context
     * @return array{severity:string,reason:string,title:string}
     */
    public function prioritize(string $type, array $detected, array $context): array
    {
        $details = $detected['details'] ?? [];
        $base = $detected['severity'] ?? ExceptionType::severity($type);
        $title = ExceptionType::title($type);
        $blocking = in_array($type, self::BLOCKING, true) || $base === Severity::ACTION_REQUIRED;
        $finalized = in_array($context['finalization'] ?? 'open', ['approved', 'locked'], true);

        if ($blocking && ! $finalized) {
            $cutoff = $this->cutoffFor($context['employee_id'], $context['date']);
            if ($cutoff !== null && TenantClock::today() > $cutoff) {
                return ['severity' => Severity::CRITICAL, 'title' => $title, 'reason' => 'Attendance for '.$context['date'].' was not finalized before the payroll cutoff on '.$cutoff.', and this issue prevents finalization.'];
            }

            return ['severity' => Severity::ACTION_REQUIRED, 'title' => $title, 'reason' => 'This issue prevents the attendance day from being finalized until it is resolved.'];
        }
        if ($blocking) {
            return ['severity' => Severity::ACTION_REQUIRED, 'title' => $title, 'reason' => 'This issue prevents the attendance day from being finalized.'];
        }

        return match ($type) {
            ExceptionType::LATE_ARRIVAL => $this->late($title, $base, $details),
            ExceptionType::OUTSIDE_GEOFENCE => ['severity' => Severity::WARNING, 'title' => $title, 'reason' => 'The punch was recorded outside the permitted location; a manager can confirm it was legitimate.'],
            ExceptionType::OVERTIME => ['severity' => Severity::INFO, 'title' => $title, 'reason' => 'Overtime was recorded for this day; it needs no action unless approval is pending.'],
            ExceptionType::INTEGRITY_REVIEW => ['severity' => Severity::WARNING, 'title' => 'Attendance integrity review required', 'reason' => 'A routine check asked for a review. It is not a conclusion about the employee.'],
            default => ['severity' => Severity::ORDER[$base] > Severity::ORDER[Severity::WARNING] ? Severity::WARNING : $base, 'title' => $title, 'reason' => 'Standard attendance rule outcome ('.$base.').'],
        };
    }

    private function late(string $title, string $base, array $details): array
    {
        if (array_key_exists('allowance_exceeded', $details)) {
            return $details['allowance_exceeded']
                ? ['severity' => Severity::WARNING, 'title' => $title, 'reason' => 'Late arrivals this month exceeded the allowance set by the attendance policy.']
                : ['severity' => Severity::INFO, 'title' => $title, 'reason' => 'Late arrival within the monthly allowance set by the attendance policy.'];
        }

        return ['severity' => Severity::ORDER[$base] > Severity::ORDER[Severity::WARNING] ? Severity::WARNING : $base, 'title' => $title, 'reason' => 'Late arrival recorded by the attendance policy.'];
    }

    /** Date (Y-m-d) by which the attendance date's month must be finalized: the cutoff day of the FOLLOWING month. */
    public function cutoffFor(int $employeeId, string $date): ?string
    {
        $tenant = app(TenantContext::class)->id;
        $day = DB::table('employee_policy_references')->join('payroll_groups', 'payroll_groups.id', '=', 'employee_policy_references.reference_id')
            ->where('employee_policy_references.tenant_id', $tenant)->where('employee_policy_references.employee_id', $employeeId)->where('employee_policy_references.kind', 'payroll_group')
            ->where('employee_policy_references.effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('employee_policy_references.effective_to')->orWhere('employee_policy_references.effective_to', '>=', $date))
            ->whereNotNull('payroll_groups.cutoff_day')->orderByDesc('employee_policy_references.effective_from')->value('payroll_groups.cutoff_day');
        $day ??= AttendanceSettings::get('cutoffs.payroll_cutoff_day');
        if (! $day) {
            return null;
        }
        $month = CarbonImmutable::parse($date)->startOfMonth()->addMonth();

        return $month->day(min((int) $day, $month->daysInMonth))->toDateString();
    }
}
