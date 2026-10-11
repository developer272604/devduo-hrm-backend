<?php

namespace App\Domain\Payroll\Contracts\Dto;

/** One side of a journal entry; amounts are decimal strings at the currency exponent. */
final class JournalLine
{
    /** @param array<string,string|null> $dimensions */
    public function __construct(
        public readonly string $account,
        public readonly string $debit,
        public readonly string $credit,
        public readonly string $currency,
        public readonly array $dimensions = [],
        public readonly ?string $componentCode = null,
        public readonly ?string $memo = null,
    ) {}
}
