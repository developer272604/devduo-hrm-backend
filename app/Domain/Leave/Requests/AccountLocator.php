<?php

namespace App\Domain\Leave\Requests;

use App\Domain\Leave\Models\LeaveAccount;

/**
 * Finds the ledger account (employee + leave type + cycle period) that covers a date. Accounts are opened by the entitlement /
 * accrual workstream; leave requests only ever post into an account that already exists.
 */
final class AccountLocator
{
    public function forDate(int $employeeId, int $leaveTypeId, string $date, bool $openOnly = true): ?LeaveAccount
    {
        return LeaveAccount::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('period_start', '<=', $date)->where('period_end', '>=', $date)
            ->when($openOnly, fn ($q) => $q->where('status', 'open'))->orderByDesc('period_start')->first();
    }
}
