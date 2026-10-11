<?php

namespace App\Domain\Attendance\Contracts;

/**
 * Edit protection for locked attendance. Every attendance mutation that could change a day's result (punch edits,
 * regularization approval, adjustments, roster changes for past dates, recalculation) must call this first.
 * Implemented by the processing/locks workstream (bound in its Boot class).
 */
interface LockGuard
{
    /** True when the employee-date is covered by an active lock (any scope: tenant, legal entity, payroll group, location, group, employee). */
    public function isLocked(int $employeeId, string $date): bool;

    /** Throws an HTTP 423 with a friendly, actionable message when locked ("Attendance for 2026-09-30 is locked ... request an unlock"). */
    public function assertUnlocked(int $employeeId, string $date): void;

    /** The active lock record (array with id, scope_type, reason, locked_at...) or null. */
    public function lockFor(int $employeeId, string $date): ?array;
}
