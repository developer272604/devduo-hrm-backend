<?php

namespace App\Domain\Lifecycle\Contracts;

use App\Domain\Payroll\Settlement\SettlementService;
use App\Domain\People\Models\Employee;
use App\Models\User;

/** Orchestrates (never duplicates) the Payroll settlement: Payroll keeps its own permission, data scope and idempotency checks. */
final class PayrollSettlementInitiator implements SettlementInitiator
{
    public function initiate(User $actor, Employee $employee, string $lastWorkingDate, array $data): string
    {
        $run = app(SettlementService::class)->create($actor, array_filter([
            'employee_uuid' => $employee->uuid, 'last_working_date' => $lastWorkingDate, 'currency' => $data['currency'],
            'idempotency_key' => $data['idempotency_key'], 'daily_rate' => $data['daily_rate'] ?? null, 'salary_days' => $data['salary_days'] ?? null,
        ], fn ($v) => $v !== null));

        return $run->uuid;
    }
}
