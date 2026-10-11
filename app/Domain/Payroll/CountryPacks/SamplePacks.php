<?php

namespace App\Domain\Payroll\CountryPacks;

/**
 * ILLUSTRATIVE sample pack for a fictional jurisdiction ("ZZ"). Numbers are invented to demonstrate slabs, caps, thresholds and formulas.
 * They are NOT the law of any country and must never be used for real payroll: the version is flagged non_authoritative and
 * requires_expert_validation, and enabling it needs the same verification gate as any pack.
 */
final class SamplePacks
{
    public const CODE = 'SAMPLE-ZZ';

    public static function definition(): array
    {
        return [
            'pack' => ['code' => self::CODE, 'country' => 'ZZ', 'name' => 'Illustrative sample pack (NOT real law)', 'description' => 'Fictional demonstration rules. Not authoritative; do not use for real payroll.'],
            'version' => [
                'effective_from' => '2026-01-01', 'tax_year_start' => '01-01',
                'metadata' => ['source' => 'Fictional example created for demonstration', 'authority' => 'none', 'non_authoritative' => true, 'requires_expert_validation' => true,
                    'notice' => 'Illustrative sample only. No real-law claim is made.'],
                'calculation_rules' => [
                    'non_authoritative' => true,
                    'concepts' => [
                        ['id' => 'sample_income_tax', 'kind' => 'tax', 'type' => 'slab', 'component_code' => 'SAMPLE_TAX', 'base' => 'taxable_gross', 'annualize' => true,
                            'slabs' => [['up_to' => '10000', 'rate' => '0'], ['up_to' => '50000', 'rate' => '10'], ['up_to' => null, 'rate' => '20']], 'rounding' => ['mode' => 'half_up', 'scale' => 2]],
                        ['id' => 'sample_pension_employee', 'kind' => 'employee_contribution', 'type' => 'rate', 'component_code' => 'SAMPLE_PEN_EE', 'base' => 'basic', 'rate' => '5', 'wage_cap' => '2000'],
                        ['id' => 'sample_pension_employer', 'kind' => 'employer_contribution', 'type' => 'rate', 'component_code' => 'SAMPLE_PEN_ER', 'base' => 'basic', 'rate' => '7', 'wage_cap' => '2000'],
                        ['id' => 'sample_flat_levy', 'kind' => 'deduction', 'type' => 'flat', 'component_code' => 'SAMPLE_LEVY', 'amount' => '10', 'when' => [['base' => 'gross', 'op' => 'gte', 'value' => '3000']]],
                    ],
                    'overtime_multipliers' => ['weekday' => '1.5', 'rest_day' => '2'],
                ],
                'validation_rules' => ['required_identifiers' => ['sample_tax_id']],
                'identifiers' => [['key' => 'sample_tax_id', 'label' => 'Sample tax identifier', 'required' => true, 'pattern' => '^[A-Z0-9]{6,12}$']],
            ],
        ];
    }
}
