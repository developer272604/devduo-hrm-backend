<?php

namespace App\Domain\Payroll\Contracts\Dto;

/**
 * Statutory lines returned by a pack. Each line: {concept, kind (tax|employee_contribution|employer_contribution|deduction|benefit|informational),
 * component_code?, amount (decimal string, positive magnitude), taxable_base?, rule_id, trace: array}.
 */
final class StatutoryResult
{
    /**
     * @param  array<int,array<string,mixed>>  $lines
     * @param  array<int,array<string,mixed>>  $warnings
     * @param  array<int,array<string,mixed>>  $errors   a non-empty list marks a statutory failure for this employee (isolated, not run-wide)
     */
    public function __construct(
        public readonly array $lines = [],
        public readonly array $warnings = [],
        public readonly array $errors = [],
    ) {}

    public function failed(): bool
    {
        return $this->errors !== [];
    }
}
