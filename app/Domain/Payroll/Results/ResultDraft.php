<?php

namespace App\Domain\Payroll\Results;

/**
 * What the calculation engine hands to the ResultStore for one employee. Component amounts are POSITIVE decimal strings already
 * rounded to the currency exponent (the store refuses unrounded values); the component kind gives the direction:
 *   earning               adds to gross
 *   reimbursement         added to net, not gross
 *   deduction, employee_contribution, tax   subtracted from net
 *   employer_contribution, benefit          employer cost / non-cash benefit: reported, not part of net
 *   informational         reported only
 *
 * components[]: {component_code, name, kind, amount, component_id?, component_version?, taxable_amount?, quantity?, formula?, formula_version?,
 *                origin?, source_input_id?, source_ref?, payslip_visible?, gl_code?, sequence?, trace?}
 */
final class ResultDraft
{
    /**
     * @param  array<int,array<string,mixed>>  $components
     * @param  array<string,mixed>  $inputSnapshot   every input used, with provenance (decimal strings only)
     * @param  array<string,mixed>  $trace           payable-day resolution, proration, retro / arrear steps, statutory rule ids
     * @param  array<int,array<string,mixed>>  $warnings
     * @param  array<int,array<string,mixed>>  $errors
     */
    public function __construct(
        public readonly int $runEmployeeId,
        public readonly string $currency,
        public readonly array $components,
        public readonly string $engineVersion,
        public readonly ?string $payableDays = null,
        public readonly ?string $workingDays = null,
        public readonly ?int $countryPackVersionId = null,
        public readonly ?int $compensationId = null,
        public readonly ?int $structureId = null,
        public readonly array $inputSnapshot = [],
        public readonly array $trace = [],
        public readonly array $warnings = [],
        public readonly array $errors = [],
        public readonly ?int $reversesId = null,
        public readonly bool $controlledCorrection = false,
    ) {}
}
