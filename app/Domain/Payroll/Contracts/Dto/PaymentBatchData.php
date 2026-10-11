<?php

namespace App\Domain\Payroll\Contracts\Dto;

/**
 * Snapshot handed to a PaymentFormat. instructions[]: {employee_uuid, employee_number, name, amount (decimal string at currency exponent),
 * reference, beneficiary: {bank_name, account_number, routing, iban, swift, ...}} - decrypted only for the duration of generation.
 */
final class PaymentBatchData
{
    /**
     * @param  array<int,array<string,mixed>>  $instructions
     * @param  array<string,mixed>  $sourceAccount
     */
    public function __construct(
        public readonly string $batchUuid,
        public readonly string $runUuid,
        public readonly string $currency,
        public readonly string $paymentDate,
        public readonly int $fileVersion,
        public readonly array $instructions,
        public readonly array $sourceAccount = [],
        public readonly string $narrative = 'Payroll',
    ) {}

    public function total(): string
    {
        return (string) \App\Domain\Payroll\Support\Decimal::sum(array_column($this->instructions, 'amount'));
    }

    public function count(): int
    {
        return count($this->instructions);
    }
}
