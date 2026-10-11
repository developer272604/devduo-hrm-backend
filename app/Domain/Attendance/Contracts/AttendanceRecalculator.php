<?php

namespace App\Domain\Attendance\Contracts;

/**
 * The ONLY way other attendance areas (punch ingestion, regularization approval, roster publication, imports,
 * device sync, adjustments) ask for attendance to be (re)calculated. Implemented by the processing service.
 *
 * Small requests (a day) run inline inside the caller's tenant context; larger ones are queued as an
 * attendance_processing_runs record. Implementations must be idempotent, skip LOCKED days (reporting them as
 * skipped_locked instead of failing), and never recalculate concurrently for the same employee/date.
 */
interface AttendanceRecalculator
{
    /** Recalculate one employee-day now. Returns the attendance_days id, or null if the day is locked/not processable. */
    public function recalculateDay(int $employeeId, string $date, string $trigger, ?int $requestedBy = null): ?int;

    /**
     * Recalculate a scope. $scope keys: employee_ids[] | department_id | location_id | employee_group_id | tenant(true), plus from/to (Y-m-d).
     * Returns the processing run uuid (queued or completed inline when small).
     */
    public function recalculate(array $scope, string $trigger, ?int $requestedBy = null, ?string $dedupeKey = null): string;
}
