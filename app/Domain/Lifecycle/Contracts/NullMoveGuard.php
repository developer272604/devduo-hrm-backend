<?php

namespace App\Domain\Lifecycle\Contracts;

use App\Domain\People\Models\Employee;

final class NullMoveGuard implements MoveGuard
{
    public function assertMovable(Employee $employee, string $effectiveDate): void {}
}
