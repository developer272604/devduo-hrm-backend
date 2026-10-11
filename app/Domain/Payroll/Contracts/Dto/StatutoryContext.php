<?php

namespace App\Domain\Payroll\Contracts\Dto;

/** Everything a statutory handler may know. All money is decimal strings in $currency. Built by the run engine from the exact pack version. */
final class StatutoryContext
{
    /**
     * @param  array<string,mixed>  $rules         calculation_rules of the pack version in force (the ONLY source of law)
     * @param  array<string,mixed>  $validationRules
     * @param  array<string,string>  $amounts      e.g. gross, taxable_gross, basic, by tax classification
     * @param  array<string,string>  $ytd          year-to-date figures by statutory concept (from locked results)
     * @param  array<string,mixed>  $employee      non-monetary facts: age band, residency, category, identifiers (masked unless required), declaration
     * @param  array<string,mixed>  $declaration   approved tax declaration data for the tax year, when the pack uses one
     */
    public function __construct(
        public readonly string $country,
        public readonly string $currency,
        public readonly string $periodStart,
        public readonly string $periodEnd,
        public readonly string $taxYear,
        public readonly int $periodsInYear,
        public readonly int $periodIndex,
        public readonly int $packVersionId,
        public readonly int $packVersion,
        public readonly array $rules,
        public readonly array $validationRules,
        public readonly array $amounts,
        public readonly array $ytd = [],
        public readonly array $employee = [],
        public readonly array $declaration = [],
    ) {}
}
