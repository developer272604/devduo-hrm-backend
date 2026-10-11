<?php

namespace App\Domain\Payroll\Contracts\Dto;

use App\Models\User;

/** What a PayrollInputProvider is asked for. employeeIds are internal employee ids already limited to the payroll group / data scope. */
final class PayrollInputQuery
{
    /** @param array<int,int>|null $employeeIds */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?User $actor = null,
        public readonly ?string $groupCode = null,
        public readonly ?array $employeeIds = null,
        public readonly bool $includeOpen = false,
    ) {}
}
