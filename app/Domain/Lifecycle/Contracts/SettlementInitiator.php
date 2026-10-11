<?php

namespace App\Domain\Lifecycle\Contracts;

use App\Domain\People\Models\Employee;
use App\Models\User;

/** Starts the full and final settlement in Payroll once clearance is complete. Returns the settlement public id. */
interface SettlementInitiator
{
    public function initiate(User $actor, Employee $employee, string $lastWorkingDate, array $data): string;
}
