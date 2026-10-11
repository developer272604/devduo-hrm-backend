<?php

namespace Tests\Concerns;

use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestSegment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\People\Models\Employee;
use App\Models\User;

/** Fixtures for the workstream D leave operations tests (types, accounts, ledger credits, approved requests with segments). */
trait LeaveOpsFixtures
{
    protected function leaveType(string $code, array $o = []): LeaveType
    {
        return LeaveType::create($o + ['name' => ucfirst(strtolower($code)).' leave', 'code' => $code, 'kind' => 'annual']);
    }

    protected function leaveAccount(Employee $employee, LeaveType $type, string $start = '2026-01-01', string $end = '2026-12-31', string $negative = '0'): LeaveAccount
    {
        return LeaveAccount::create(['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'period_start' => $start, 'period_end' => $end, 'negative_limit' => $negative]);
    }

    protected function credit(LeaveAccount $account, string $units, string $key, string $date = '2026-01-01', ?string $expires = null, string $bucket = 'accrued', string $entryType = 'accrual')
    {
        return app(LedgerService::class)->post(new LedgerPosting($account->id, $entryType, $bucket, $units, $date, $key, expiresOn: $expires));
    }

    /** Approved request with one charged segment per date. @param array<int,string> $dates */
    protected function approvedLeave(Employee $employee, LeaveType $type, array $dates, array $o = []): LeaveRequest
    {
        $request = LeaveRequest::create(($o['request'] ?? []) + ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'status' => 'approved', 'start_date' => min($dates), 'end_date' => max($dates),
            'requested_units' => count($dates), 'charged_units' => count($dates), 'decided_at' => $o['decided_at'] ?? now(), 'submitted_at' => now()]);
        foreach ($dates as $d) {
            LeaveRequestSegment::create(['request_id' => $request->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'leave_date' => $d, 'units' => 1, 'minutes' => 480, 'pay_class' => $o['pay_class'] ?? 'paid', 'charged' => true, 'status' => 'active']);
        }

        return $request;
    }

    protected function managerUser(string $name = 'Mia Manager'): User
    {
        $this->hire($name, ['role' => 'Manager']);

        return User::where('email', strtolower(explode(' ', $name)[0]).'@alpha.test')->firstOrFail();
    }

    /** A second administrator (the "checker" in maker-checker). */
    protected function secondAdmin(string $email = 'checker@alpha.test'): User
    {
        return $this->userWithRole('Workspace administrator', $email, 'Checker Admin');
    }
}
