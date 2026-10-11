<?php

namespace App\Domain\Lifecycle\Contracts;

/** A department or module that can hold up an exit (assets, expenses, loans, leave, payroll). Later phases replace the null implementations. */
interface ClearanceProvider
{
    public function key(): string;

    /** @return array<int,string> human-readable blockers; empty when the employee is clear for this department */
    public function blockers(int $employeeId): array;
}
