<?php

namespace App\Domain\Leave\Contracts;

/**
 * Edit protection for locked leave periods (payroll cutoff). LedgerService calls this before every posting, and request
 * services must call it before changing an approved request that touches a locked date. Bound by LeaveFoundationBoot to the
 * database implementation; workstream D owns the lock lifecycle (create / unlock request / maker-checker) and may extend it.
 */
interface LeaveLockGuard
{
    public function isLocked(int $employeeId, string $date): bool;

    /** Throws HTTP 423 with an actionable message when locked. */
    public function assertUnlocked(int $employeeId, string $date): void;

    /** @return array<string,mixed>|null the active lock (id, uuid, scope_type, scope_id, scope_label, reason, start_date, end_date) */
    public function lockFor(int $employeeId, string $date): ?array;
}
