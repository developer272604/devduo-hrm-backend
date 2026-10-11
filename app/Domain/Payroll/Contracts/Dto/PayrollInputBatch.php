<?php

namespace App\Domain\Payroll\Contracts\Dto;

/** Result of one provider call: lines plus data-completeness facts the readiness check and validation engine consume. */
final class PayrollInputBatch
{
    /**
     * @param  array<int,PayrollInputLine>  $lines
     * @param  array<int,array<string,mixed>>  $warnings    [{key,count,message}]
     */
    public function __construct(
        public readonly string $source,
        public readonly string $from,
        public readonly string $to,
        public readonly array $lines,
        public readonly array $warnings = [],
        public readonly bool $complete = true,
        public readonly ?string $reference = null,
    ) {}

    /** @return array<int,PayrollInputLine> */
    public function forEmployee(string $employeeUuid): array
    {
        return array_values(array_filter($this->lines, fn (PayrollInputLine $l) => $l->employeeUuid === $employeeUuid));
    }
}
