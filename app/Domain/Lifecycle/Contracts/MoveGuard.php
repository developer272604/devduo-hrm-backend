<?php

namespace App\Domain\Lifecycle\Contracts;

use App\Domain\People\Models\Employee;

/** Lets attendance/leave/payroll veto an effective-dated internal move (closed periods, payroll locks). Abort with 409 to refuse. */
interface MoveGuard
{
    public function assertMovable(Employee $employee, string $effectiveDate): void;
}
