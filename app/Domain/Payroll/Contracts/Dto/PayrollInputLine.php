<?php

namespace App\Domain\Payroll\Contracts\Dto;

/**
 * One standardized payroll input line. Every line identifies its SOURCE and carries a provenance reference.
 * Quantities are DECIMAL STRINGS (never floats); money is not produced by Attendance / Leave providers.
 */
final class PayrollInputLine
{
    /** @param array<string,mixed> $meta */
    public function __construct(
        public readonly string $source,          // attendance | leave | overtime | ...
        public readonly string $kind,            // payable_days, present_days, absent_days, approved_overtime_minutes, leave_unpaid, leave_lop, ...
        public readonly string $employeeUuid,
        public readonly ?string $employeeNumber,
        public readonly ?string $quantity,
        public readonly string $unit,            // days | minutes | hours | leave_units | count
        public readonly string $reference,       // provenance, e.g. attendance:{group}:{from}:{to}, leave:request:{uuid}
        public readonly bool $isFinal = true,    // false = built from open / non-final source data
        public readonly bool $lateAdjustment = false,
        public readonly array $meta = [],
    ) {}

    /** Stable key: the same source fact never becomes two inputs. */
    public function dedupeKey(string $periodKey): string
    {
        return substr(hash('sha256', implode('|', [$this->source, $this->kind, $this->employeeUuid, $periodKey, $this->reference])), 0, 40);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
