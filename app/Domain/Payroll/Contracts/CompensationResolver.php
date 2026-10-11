<?php

namespace App\Domain\Payroll\Contracts;

/**
 * Resolves the APPROVED compensation that applies to an employee on a date (as-of, never "now"). Owner: workstream A (binds it in ConfigBoot).
 * Consumers: run engine (B), simulator / impact preview / explainer (D), Employee 360 and ESS (C/D). Never returns unapproved or overlapping rows.
 *
 * current() returns null when nothing applies, otherwise:
 *   compensation_id, structure_id, currency, pay_frequency, effective_from, effective_to,
 *   base_pay, gross_reference, annual_compensation, hourly_rate, daily_rate   (decimal strings or null)
 *   lines[]: {component_id, component_code, component_version, kind, name, amount (decimal string), formula, formula_version, sequence}
 */
interface CompensationResolver
{
    /** @return array<string,mixed>|null */
    public function current(int $employeeId, string $date): ?array;

    /**
     * Every compensation segment overlapping [from, to], ordered by effective_from (mid-period revisions, retro engine).
     *
     * @return array<int,array<string,mixed>>
     */
    public function between(int $employeeId, string $from, string $to): array;
}
