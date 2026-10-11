<?php

namespace App\Domain\Attendance\Contracts;

/**
 * Integration contract with the future Leave module (Phase 4). Attendance never hard-codes leave rules;
 * it asks this provider what approved leave covers on a date and reacts to events.
 *
 * Coverage: minutes of the day covered; portion full_day | first_half | second_half | partial; reference = opaque leave id.
 */
interface LeaveDataProvider
{
    /** @return array{minutes:int,portion:string,reference:?string,paid:?bool}|null null when no approved leave covers the date */
    public function coverage(int $employeeId, string $date): ?array;

    /** Employees with approved leave covering any day in the range, keyed "employeeId|Y-m-d" (batch version for large runs). */
    public function coverageForRange(array $employeeIds, string $from, string $to): array;
}
