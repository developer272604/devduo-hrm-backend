<?php

namespace App\Domain\Leave\Contracts\Dto;

/** Input of DurationCalculator. Dates are logical local dates (Y-m-d); times are local HH:MM(:SS) in `timezone` (default: the employee's). */
final class DurationQuery
{
    public function __construct(
        public readonly int $employeeId,
        public readonly int $leaveTypeId,
        public readonly string $startDate,
        public readonly string $endDate,
        /** full_day | first_half | second_half | quarter | hours */
        public readonly string $portion = 'full_day',
        public readonly ?string $startTime = null,
        public readonly ?string $endTime = null,
        public readonly ?string $timezone = null,
        /** Evaluate rules of this version instead of the one resolved for the start date (policy sandbox / preview). */
        public readonly ?int $policyVersionId = null,
        /** Request being modified: ignored when checking overlaps and notice. */
        public readonly ?int $ignoreRequestId = null,
        /** "Today" (Y-m-d) for notice/backdate rules; null = tenant today. Inject for deterministic tests. */
        public readonly ?string $submittedOn = null,
    ) {}
}
