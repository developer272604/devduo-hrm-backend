<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Payroll\Contracts\Dto\AccountingContext;
use App\Domain\Payroll\Contracts\Dto\GeneratedFile;
use App\Domain\Payroll\Contracts\Dto\JournalLine;

/**
 * Turns the approved payroll components of a run into balanced journal lines using accounting_mappings (component -> ledger account,
 * cost centre, department, legal entity, location). Adapters for a specific ledger / file format are registered by code. Pure function of
 * the context: reconciliation (net vs payments vs journal) is performed by the caller.
 */
interface AccountingMapper
{
    public function code(): string;

    /**
     * @return array<int,JournalLine>  must balance (sum debit == sum credit); unmapped components throw a user-facing exception
     */
    public function map(AccountingContext $context): array;

    /** Serialises lines for export (csv, json, ledger specific). */
    public function export(array $lines, AccountingContext $context): GeneratedFile;
}
