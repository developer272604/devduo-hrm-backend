<?php

namespace App\Domain\Payroll\Engine;

use App\Domain\Payroll\Contracts\Dto\StatutoryContext;
use App\Domain\Payroll\Contracts\EmployeeCalculator;
use App\Domain\Payroll\Contracts\FormulaEvaluator;
use App\Domain\Payroll\Results\ResultDraft;
use App\Domain\Payroll\Results\ResultStore;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\Payroll\Support\Rounding;
use App\Domain\Payroll\Support\StatutoryPackRegistry;
use Throwable;

/**
 * PURE per-employee payroll calculation (no database, no clock, no randomness). Same request => same draft, so a recalculation can be
 * proven (RunCalculator::proof). Money is decimal-string maths with explicit rounding. One employee's failure is returned in the draft
 * (errors[]), never thrown. Statutory content only comes from the StatutoryPack named in `request.statutory`.
 * Request shape: docs/api/payroll-runs.md ("Calculator request").
 */
final class PayrollCalculator implements EmployeeCalculator
{
    public function __construct(private FormulaEvaluator $formulas, private StatutoryPackRegistry $packs) {}

    public function calculate(array $request): ResultDraft
    {
        $currency = $request['currency'];
        $exp = Money::exponent($currency);
        $engine = $request['engine_version'] ?? PayrollSettings::ENGINE_VERSION;
        $runEmployeeId = (int) ($request['run_employee_id'] ?? 0);
        $mode = $request['rounding']['mode'] ?? Rounding::HALF_UP;
        $warnings = [];
        $errors = [];
        $trace = [];
        $segments = $request['segments'] ?? [];
        $current = $request['compensation'] ?? null;

        try {
            if (! $segments && $current) {
                $segments = [['from' => $request['period_start'], 'to' => $request['period_end'], 'compensation' => $current]];
            }
            if (! $segments) {
                throw new CalculationFailed('no_compensation', 'No approved compensation applies to this employee in the period.');
            }
            [$lop, $overtimeHours, $attendancePayable] = $this->summariseInputs($request);
            if (isset($request['overrides']['lop_days'])) {
                $lop = Decimal::str($request['overrides']['lop_days'], 4);
            }
            $days = PayableDays::resolve(['period_start' => $request['period_start'], 'period_end' => $request['period_end'], 'employment_start' => $request['employment']['start'] ?? null,
                'employment_end' => $request['employment']['end'] ?? null, 'attendance_payable' => $attendancePayable, 'lop_days' => $lop, 'days_basis' => $request['days_basis'] ?? null]);
            $trace['payable_days'] = $days;

            $acc = [];
            foreach ($segments as $seg) {
                $segFrom = max($seg['from'], $days['employment_start']);
                $segTo = min($seg['to'], $days['employment_end']);
                if ($segFrom > $segTo) {
                    continue;
                }
                $segDays = (int) round((strtotime($segTo) - strtotime($segFrom)) / 86400) + 1;
                $segPayable = $days['employment_days'] > 0 ? Decimal::str(Decimal::div(Decimal::mul($days['payable_days'], $segDays), $days['employment_days'], 6), 6) : '0';
                $comp = $seg['compensation'];
                $vars = $this->variables($comp, $days, $overtimeHours);
                $lines = $comp['lines'] ?? [];
                usort($lines, fn ($a, $b) => [$a['sequence'] ?? 100, $a['component_code']] <=> [$b['sequence'] ?? 100, $b['component_code']]);
                foreach ($lines as $line) {
                    $meta = $line['meta'] ?? [];
                    $full = $line['amount'];
                    $formulaTrace = null;
                    if (! empty($line['formula'])) {
                        $r = $this->formulas->evaluate($line['formula'], $vars, 6);
                        $full = $r->value;
                        $formulaTrace = $r->trace();
                    }
                    $vars[strtoupper($line['component_code'])] = Decimal::str($full, 6);
                    $dependent = ! empty($meta['attendance_dependent']) || ! empty($meta['lop_dependent']);
                    $factor = PayableDays::factor($meta['proration'] ?? 'none', $dependent, $segDays, $segPayable, $days);
                    $part = Decimal::mul($full, $factor);
                    $key = $line['component_code'].'|'.$line['kind'];
                    $acc[$key] ??= ['line' => $line, 'sum' => Decimal::of(0), 'segments' => []];
                    $acc[$key]['sum'] = $acc[$key]['sum']->plus($part);
                    $acc[$key]['segments'][] = ['from' => $segFrom, 'to' => $segTo, 'full' => Decimal::str($full, 6), 'factor' => $factor, 'formula' => $formulaTrace];
                }
            }

            $components = [];
            $seq = 0;
            $taxable = Decimal::of(0);
            $basic = '0';
            $preTax = Decimal::of(0);
            foreach ($acc as $row) {
                $line = $row['line'];
                $meta = $line['meta'] ?? [];
                $amount = $this->clamp($this->roundComponent($row['sum'], $meta, $exp, $mode), $meta);
                $isTaxable = $line['kind'] === 'earning' && ! in_array($meta['tax_classification'] ?? 'taxable', ['exempt', 'non_taxable'], true);
                $components[] = ['component_code' => $line['component_code'], 'name' => $line['name'] ?? $line['component_code'], 'kind' => $line['kind'], 'amount' => $amount,
                    'component_id' => $line['component_id'] ?? null, 'component_version' => $line['component_version'] ?? null,
                    'taxable_amount' => $line['kind'] === 'earning' ? ($isTaxable ? $amount : Decimal::str(0, $exp)) : null,
                    'formula' => $line['formula'] ?? null, 'formula_version' => $line['formula_version'] ?? null, 'origin' => 'compensation',
                    'payslip_visible' => $meta['payslip_visible'] ?? true, 'gl_code' => $meta['gl_code'] ?? null, 'sequence' => $seq++,
                    'trace' => ['segments' => $row['segments'], 'proration' => $meta['proration'] ?? 'none']];
                if ($isTaxable) {
                    $taxable = $taxable->plus($amount);
                }
                if ($line['kind'] === 'earning' && ($line['component_code'] === 'BASE' || ($meta['statutory_mapping'] ?? null) === 'basic')) {
                    $basic = $amount;
                }
                if ($line['kind'] === 'deduction' && ($meta['tax_classification'] ?? '') === 'pre_tax') {
                    $preTax = $preTax->plus($amount);
                }
            }

            if (Decimal::cmp($overtimeHours, 0) > 0) {
                $rate = $segments[count($segments) - 1]['compensation']['hourly_rate'] ?? null;
                if ($rate === null) {
                    $warnings[] = ['code' => 'overtime_without_rate', 'message' => 'Overtime was approved but no hourly rate is available; overtime was not paid.'];
                } else {
                    $mult = $request['overtime']['multiplier'] ?? '1';
                    $amt = Rounding::apply(Decimal::mul(Decimal::mul($rate, $overtimeHours), $mult), $exp, $mode);
                    $components[] = ['component_code' => $request['overtime']['component_code'] ?? 'OVERTIME', 'name' => 'Overtime', 'kind' => 'earning', 'amount' => $amt, 'taxable_amount' => $amt,
                        'quantity' => $overtimeHours, 'origin' => 'input', 'sequence' => $seq++, 'trace' => ['hours' => $overtimeHours, 'rate' => $rate, 'multiplier' => $mult]];
                    $taxable = $taxable->plus($amt);
                }
            }

            $inputs = $request['inputs'] ?? [];
            usort($inputs, fn ($a, $b) => ($a['id'] ?? 0) <=> ($b['id'] ?? 0));
            foreach ($inputs as $in) {
                if (($in['amount'] ?? null) === null || Decimal::isZero($in['amount'])) {
                    continue;
                }
                $kind = ($in['meta']['component_kind'] ?? null) ?: ($in['source'] === 'reimbursement' ? 'reimbursement' : 'earning');
                $amt = Rounding::apply(Decimal::of($in['amount'])->abs(), $exp, $mode);
                $components[] = ['component_code' => $in['component_code'] ?: strtoupper($in['source']), 'name' => ucfirst(str_replace('_', ' ', $in['source'])), 'kind' => $kind, 'amount' => $amt,
                    'taxable_amount' => $kind === 'earning' ? $amt : null, 'origin' => 'input', 'source_input_id' => $in['id'] ?? null, 'source_ref' => $in['source_ref'] ?? null, 'sequence' => $seq++];
                if ($kind === 'earning') {
                    $taxable = $taxable->plus($amt);
                }
            }
            if (isset($request['overrides']['bonus'])) {
                $amt = Rounding::apply($request['overrides']['bonus'], $exp, $mode);
                $components[] = ['component_code' => 'BONUS', 'name' => 'Bonus (simulated)', 'kind' => 'earning', 'amount' => $amt, 'taxable_amount' => $amt, 'origin' => 'input', 'sequence' => $seq++];
                $taxable = $taxable->plus($amt);
            }

            // adjustments: signed (positive pays, negative recovers); kind "recovery" always recovers
            foreach ($request['adjustments'] ?? [] as $adj) {
                $signed = Decimal::of($adj['amount']);
                $reversesKind = $adj['kind'] === 'reversal' ? ($adj['trace']['reverses_kind'] ?? null) : null;
                $pays = $adj['kind'] === 'reversal' && $reversesKind === 'recovery' ? true : ($adj['kind'] !== 'recovery' && ! $signed->isNegative());
                $amt = Rounding::apply($signed->abs(), $exp, $mode);
                if (Decimal::isZero($amt)) {
                    continue;
                }
                $code = ($adj['component_code'] ?? null) ?: ($pays ? strtoupper($adj['kind']) : strtoupper($adj['kind']).'_RECOVERY');
                $taxablePay = $pays && $adj['kind'] !== 'manual_net' && $reversesKind !== 'manual_net' && $reversesKind !== 'recovery';
                $originKind = $reversesKind ?? $adj['kind'];
                $components[] = ['component_code' => $code, 'name' => ucfirst(str_replace('_', ' ', $adj['kind'])).($pays ? '' : ' recovery'), 'kind' => $pays ? 'earning' : 'deduction', 'amount' => $amt,
                    'taxable_amount' => $taxablePay ? $amt : null, 'origin' => in_array($originKind, ['arrear', 'retro'], true) ? $originKind : 'adjustment',
                    'source_ref' => 'adjustment:'.($adj['id'] ?? ''), 'sequence' => $seq++, 'trace' => $adj['trace'] ?? null];
                if ($taxablePay) {
                    $taxable = $taxable->plus($amt);
                }
            }

            $gross = Money::sum(array_map(fn ($c) => Money::of($c['amount'], $currency), array_values(array_filter($components, fn ($c) => $c['kind'] === 'earning'))), $currency);
            $benefitFormulaVars = ['BASE' => Decimal::str($basic, 6), 'GROSS' => Decimal::str($gross->toString($exp), 6), 'GROSS_REFERENCE' => Decimal::str($current['gross_reference'] ?? $gross->toString($exp), 6),
                'TAXABLE_GROSS' => Decimal::str(Decimal::max($taxable->minus($preTax), 0), 6), 'PAYABLE_DAYS' => Decimal::str($days['payable_days'], 6), 'WORKING_DAYS' => Decimal::str($days['basis'], 6),
                'PERIOD_DAYS' => Decimal::str($days['period_days'], 6), 'LOP_DAYS' => Decimal::str($days['lop_days'], 6)];
            if (! empty($request['statutory'])) {
                $s = $request['statutory'];
                $ctx = new StatutoryContext($s['country'], $currency, $request['period_start'], $request['period_end'], $s['tax_year'], (int) $s['periods_in_year'], (int) $s['period_index'],
                    (int) $s['pack_version_id'], (int) $s['pack_version'], $s['rules'] ?? [], $s['validation_rules'] ?? [],
                    ['gross' => $gross->toString($exp), 'taxable_gross' => Decimal::str(Decimal::max($taxable->minus($preTax), 0), $exp), 'basic' => Decimal::str($basic, $exp)],
                    $s['ytd'] ?? [], $s['employee'] ?? [], $s['declaration'] ?? []);
                $res = $this->packs->pack($s['handler'])->calculate($ctx);
                array_push($warnings, ...$res->warnings);
                array_push($errors, ...$res->errors);
                foreach ($res->lines as $l) {
                    $amt = Rounding::apply(Decimal::of($l['amount'])->abs(), $exp, $mode);
                    $components[] = ['component_code' => $l['component_code'] ?? strtoupper($l['concept']), 'name' => $l['name'] ?? ucfirst(str_replace('_', ' ', $l['concept'])), 'kind' => $l['kind'],
                        'amount' => $amt, 'taxable_amount' => null, 'origin' => 'statutory', 'source_ref' => 'rule:'.($l['rule_id'] ?? ''), 'sequence' => $seq++,
                        'trace' => ($l['trace'] ?? []) + ['pack_version_id' => $s['pack_version_id']]];
                }
                $trace['statutory'] = ['pack_version_id' => $s['pack_version_id'], 'pack_version' => $s['pack_version'], 'handler' => $s['handler']];
            }

            foreach ($request['loan_installments'] ?? [] as $loan) {
                $components[] = ['component_code' => $loan['component_code'] ?? 'LOAN_RECOVERY', 'name' => 'Loan recovery', 'kind' => 'deduction', 'amount' => Rounding::apply($loan['amount'], $exp, $mode),
                    'origin' => 'loan', 'source_ref' => $loan['reference'] ?? 'loan_installment:'.$loan['id'], 'sequence' => $seq++];
            }

            foreach ($request['benefit_enrollments'] ?? [] as $benefit) {
                $from = max($request['period_start'], $benefit['effective_from']);
                $to = min($request['period_end'], $benefit['effective_to'] ?? $request['period_end']);
                if ($from > $to) {
                    continue;
                }
                $periodDays = $this->calendarDays($request['period_start'], $request['period_end']);
                $coveredDays = $this->calendarDays($from, $to);
                $factor = Decimal::str(Decimal::div($coveredDays, $periodDays, 8), 8);
                $baseCode = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $benefit['component_code'] ?: 'BENEFIT_'.$benefit['plan_code']));
                $contributionRules = $benefit['contributions'] ?? [];
                $vars = $benefitFormulaVars + ['COVERED_DAYS' => Decimal::str($coveredDays, 6), 'COVERED_PERIOD_DAYS' => Decimal::str($periodDays, 6), 'PRORATION_FACTOR' => Decimal::str($factor, 6)];
                foreach ([
                    ['field' => 'employee_contribution', 'formula' => 'employee_formula', 'suffix' => '_EE', 'kind' => 'employee_contribution', 'name' => 'Employee benefit contribution'],
                    ['field' => 'employer_contribution', 'formula' => 'employer_formula', 'suffix' => '_ER', 'kind' => 'employer_contribution', 'name' => 'Employer benefit contribution'],
                ] as $contribution) {
                    $fixed = $benefit[$contribution['field']] ?? '0';
                    $formula = trim((string) ($contributionRules[$contribution['formula']] ?? ''));
                    $formulaTrace = null;
                    if ($formula !== '') {
                        $result = $this->formulas->evaluate($formula, $vars, 6);
                        $fixed = $result->value;
                        $formulaTrace = $result->trace();
                    }
                    if (Decimal::isNegative($fixed)) {
                        throw new CalculationFailed('negative_benefit_contribution', 'Benefit contribution formulas must return non-negative amounts.');
                    }
                    if (Decimal::isZero($fixed)) {
                        continue;
                    }
                    $amount = Rounding::apply($formula !== '' ? $fixed : Decimal::mul($fixed, $factor), $exp, $mode);
                    if (Decimal::isZero($amount)) {
                        continue;
                    }
                    $components[] = ['component_code' => substr($baseCode, 0, 35).$contribution['suffix'], 'name' => $contribution['name'].' - '.$benefit['plan_name'],
                        'kind' => $contribution['kind'], 'amount' => $amount, 'origin' => 'benefit', 'source_ref' => 'benefit:'.$benefit['enrollment_uuid'], 'sequence' => $seq++,
                        'trace' => ['enrollment_uuid' => $benefit['enrollment_uuid'], 'plan_code' => $benefit['plan_code'], 'fixed_per_period' => $formula === '' ? $fixed : null, 'formula' => $formulaTrace,
                            'covered_from' => $from, 'covered_to' => $to, 'covered_days' => $coveredDays, 'period_days' => $periodDays, 'proration_factor' => $factor,
                            'formula_prorates_itself' => $formula !== '']];
                }
            }

            $totals = ResultStore::totals($components, $currency);
            if (Decimal::isNegative($totals['net'])) {
                $warnings[] = ['code' => 'negative_net', 'message' => 'Net pay is negative.'];
            }
            if (Decimal::isZero($totals['gross']) && Decimal::isZero($totals['net'])) {
                $warnings[] = ['code' => 'zero_pay', 'message' => 'Gross and net pay are zero.'];
            }

            return new ResultDraft($runEmployeeId, $currency, $components, $engine, $days['payable_days'], Decimal::str($days['basis'], 4), $request['statutory']['pack_version_id'] ?? null,
                $current['compensation_id'] ?? null, $current['structure_id'] ?? null, ['request' => $request], $trace, $warnings, $errors);
        } catch (CalculationFailed $e) {
            return $this->failed($runEmployeeId, $currency, $engine, $request, $e->reasonCode, $e->getMessage());
        } catch (Throwable $e) {
            return $this->failed($runEmployeeId, $currency, $engine, $request, 'calculation_error', 'The calculation failed: '.$e->getMessage());
        }
    }

    private function failed(int $id, string $currency, string $engine, array $request, string $code, string $message): ResultDraft
    {
        return new ResultDraft($id, $currency, [], $engine, inputSnapshot: ['request' => $request], errors: [['code' => $code, 'message' => $message]]);
    }

    private function calendarDays(string $from, string $to): int
    {
        $start = new \DateTimeImmutable($from, new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($to, new \DateTimeZone('UTC'));

        return (int) $start->diff($end)->days + 1;
    }

    /** @return array{0:string,1:string,2:?string} LOP days, overtime hours, attendance payable days */
    private function summariseInputs(array $request): array
    {
        $lop = Decimal::of(0);
        $ot = Decimal::of(0);
        $att = null;
        foreach ($request['inputs'] ?? [] as $in) {
            $q = $in['quantity'] ?? null;
            if ($q === null) {
                continue;
            }
            if (in_array($in['kind'], ['leave_lop', 'leave_unpaid'], true)) {
                $lop = $lop->plus($q);
            } elseif ($in['kind'] === 'approved_overtime_minutes') {
                $ot = $ot->plus(Decimal::div($q, 60, 6));
            } elseif ($in['kind'] === 'overtime_hours') {
                $ot = $ot->plus($q);
            } elseif ($in['kind'] === 'payable_days' && $in['source'] === 'attendance') {
                $att = Decimal::str($q, 4);
            }
        }

        return [Decimal::str($lop, 4), Decimal::str($ot, 6), $att];
    }

    /** @return array<string,string> */
    private function variables(array $comp, array $days, string $overtimeHours): array
    {
        return ['BASE' => Decimal::str($comp['base_pay'] ?? '0', 6), 'GROSS_REFERENCE' => Decimal::str($comp['gross_reference'] ?? '0', 6), 'WORKING_DAYS' => Decimal::str($days['basis'], 6),
            'PAYABLE_DAYS' => Decimal::str($days['payable_days'], 6), 'PERIOD_DAYS' => Decimal::str($days['period_days'], 6), 'LOP_DAYS' => Decimal::str($days['lop_days'], 6),
            'OVERTIME_UNITS' => Decimal::str($overtimeHours, 6), 'HOURLY_RATE' => Decimal::str($comp['hourly_rate'] ?? '0', 6), 'DAILY_RATE' => Decimal::str($comp['daily_rate'] ?? '0', 6)];
    }

    private function roundComponent($sum, array $meta, int $exp, string $mode): string
    {
        $scale = min((int) ($meta['rounding_scale'] ?? $exp), $exp);

        return Rounding::apply($sum, $scale, $meta['rounding_mode'] ?? $mode, $meta['rounding_increment'] ?? null);
    }

    private function clamp(string $amount, array $meta): string
    {
        if (isset($meta['min_amount']) && $meta['min_amount'] !== '' && Decimal::cmp($amount, $meta['min_amount']) < 0) {
            $amount = Decimal::str($meta['min_amount'], 4);
        }
        if (isset($meta['max_amount']) && $meta['max_amount'] !== '' && Decimal::cmp($amount, $meta['max_amount']) > 0) {
            $amount = Decimal::str($meta['max_amount'], 4);
        }

        return $amount;
    }
}
