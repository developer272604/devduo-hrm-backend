<?php

namespace App\Domain\Attendance\Engine;

use Carbon\CarbonImmutable;

/**
 * Everything the pure calculator needs. The calculator performs NO database access, reads NO clock and uses NO
 * randomness: identical input always gives identical output, which is what makes results reproducible,
 * explainable and simulatable (the policy simulator runs the same input with different $rules).
 */
final class CalculationInput
{
    /**
     * @param  array<int,Punch>  $punches  raw punches + virtual punches from approved regularizations/adjustments, any order
     * @param  array<string,mixed>  $rules  MERGED policy rules (PolicyRules::merge(...)) — never partial
     * @param  array<int,array{start:CarbonImmutable,end:CarbonImmutable,type:string,id:string}>  $onDuty  approved on-duty / remote intervals (UTC)
     * @param  array{minutes:int,portion:string,reference:?string}|null  $leave  placeholder coverage from LeaveDataProvider (null = none)
     * @param  array{late_count:int,early_count:int,late_minutes:int}  $monthToDate  occurrences BEFORE this date in the same month (for allowance rules)
     * @param  array<int,array{start:CarbonImmutable,end:CarbonImmutable}>  $approvedOvertime  approved overtime windows/minutes carried as total minutes in 'minutes'
     */
    public function __construct(
        public readonly DaySchedule $schedule,
        public readonly array $rules,
        public readonly array $punches,
        public readonly CarbonImmutable $now,                 // injected "now" (UTC) so tests and recalculation are deterministic
        public readonly array $onDuty = [],
        public readonly ?array $leave = null,
        public readonly array $monthToDate = ['late_count' => 0, 'early_count' => 0, 'late_minutes' => 0],
        public readonly int $approvedOvertimeMinutes = 0,
        public readonly bool $remoteApproved = false,
        public readonly bool $employeeTypeOvertimeEligible = true,
        public readonly ?string $policyLabel = null,           // e.g. "India General Attendance Policy v3" for explanations
        public readonly ?int $policyVersionId = null,
        public readonly ?string $previousDayEndUtc = null,      // for rest-period checks (end of the previous scheduled/actual work)
        public readonly array $compliance = [],                 // merged compliance rules {min_rest_minutes,max_daily_minutes,...}
    ) {}
}
