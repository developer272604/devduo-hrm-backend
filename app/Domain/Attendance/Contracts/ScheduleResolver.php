<?php

namespace App\Domain\Attendance\Contracts;

use App\Domain\Attendance\Engine\DaySchedule;

/**
 * Answers "who was expected to work, and when?" for one employee and LOGICAL date.
 *
 * Resolution order (deterministic, recorded in DaySchedule::notes):
 *   1. employment period (not employed => kind not_scheduled)
 *   2. published roster entry for the date (current, non-superseded)  -> source "roster"
 *   3. shift assignment / rotation, by specificity then priority       -> source "assignment" | "rotation"
 *   4. work week (weekly off / half day / custom duration) and holiday calendar (full / half holidays)
 * Org attributes (department, location, ...) are read AS OF the date from employee_assignments, never "now".
 */
interface ScheduleResolver
{
    public function resolve(int $employeeId, string $date): DaySchedule;

    /** Resolve a date range efficiently (batch-loads rosters, assignments, calendars once). @return array<string,DaySchedule> keyed by Y-m-d */
    public function resolveRange(int $employeeId, string $from, string $to): array;
}
