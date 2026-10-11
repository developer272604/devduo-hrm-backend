<?php

namespace App\Domain\Payroll\Contracts\Dto;

/**
 * components[]: {component_code, kind, amount, gl_code?, dimensions: {legal_entity, department, cost_center, location}} aggregated per
 * employee dimension set; mappings[]: rows of accounting_mappings in force on $postingDate.
 */
final class AccountingContext
{
    /**
     * @param  array<int,array<string,mixed>>  $components
     * @param  array<int,array<string,mixed>>  $mappings
     */
    public function __construct(
        public readonly string $runUuid,
        public readonly string $currency,
        public readonly string $postingDate,
        public readonly array $components,
        public readonly array $mappings,
        public readonly int $version = 1,
    ) {}
}
