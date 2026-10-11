<?php

namespace App\Domain\Leave\Contracts;

use App\Domain\Leave\Contracts\Dto\DurationQuery;
use App\Domain\Leave\Contracts\Dto\DurationResult;

/**
 * Turns a leave request (dates/times/portion) into per-day segments plus an explainable charge, using the effective
 * roster/shift/work week/holidays/weekly offs/timezone (Attendance ScheduleResolver + HolidayService), sandwich rules,
 * min/max consecutive, notice and backdating limits from the resolved policy version.
 * Implemented by workstream C (Leave/Duration/DurationEngine). Deterministic and side-effect free.
 */
interface DurationCalculator
{
    public function calculate(DurationQuery $query): DurationResult;
}
