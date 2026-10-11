<?php

namespace App\Domain\Payroll\Results;

use App\Domain\Payroll\Contracts\ResultStore as ResultStoreContract;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollResultComponent;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Support\Checksum;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Domain\Payroll\Support\PayrollStates;
use Illuminate\Support\Facades\DB;

/** Append-only writer of payroll results (see Contracts\ResultStore for the contract). */
final class ResultStore implements ResultStoreContract
{
    /** Run statuses in which ordinary recalculation is refused. */
    private const FROZEN = ['approved', 'locked', 'outputs', 'payment', 'closed', 'cancelled'];

    public function record(ResultDraft $draft): PayrollCalculationResult
    {
        $components = $this->normalize($draft);
        $totals = self::totals($components, $draft->currency);
        $checksum = Checksum::of($this->fingerprint($draft, $components));

        return DB::transaction(function () use ($draft, $components, $totals, $checksum) {
            $runEmployee = PayrollRunEmployee::whereKey($draft->runEmployeeId)->lockForUpdate()->firstOrFail();
            $run = PayrollRun::whereKey($runEmployee->run_id)->lockForUpdate()->firstOrFail();
            if ($run->currency !== $draft->currency) {
                throw ResultRejected::invalid('The result currency '.$draft->currency.' does not match the run currency '.$run->currency.'.');
            }

            $current = PayrollCalculationResult::where('run_employee_id', $runEmployee->id)->where('is_current', true)->first();
            if ($current && hash_equals($current->checksum, $checksum)) {
                return $current; // idempotent: identical calculation
            }
            if (in_array($run->status, self::FROZEN, true) && ! $draft->controlledCorrection) {
                throw ResultRejected::locked($run->status);
            }

            $version = (int) PayrollCalculationResult::where('run_employee_id', $runEmployee->id)->max('version') + 1;
            $result = PayrollCalculationResult::create([
                'run_id' => $run->id, 'run_employee_id' => $runEmployee->id, 'employee_id' => $runEmployee->employee_id, 'version' => $version, 'is_current' => true,
                'reverses_id' => $draft->reversesId, 'status' => $draft->errors ? 'failed' : ($draft->warnings ? 'warning' : 'ok'), 'currency' => $draft->currency,
                'gross' => $totals['gross'], 'total_deductions' => $totals['total_deductions'], 'total_taxes' => $totals['total_taxes'], 'employer_contributions' => $totals['employer_contributions'],
                'benefits' => $totals['benefits'], 'reimbursements' => $totals['reimbursements'], 'net' => $totals['net'],
                'payable_days' => $draft->payableDays === null ? null : Decimal::str($draft->payableDays, 4), 'working_days' => $draft->workingDays === null ? null : Decimal::str($draft->workingDays, 4), 'engine_version' => $draft->engineVersion,
                'country_pack_version_id' => $draft->countryPackVersionId, 'compensation_id' => $draft->compensationId, 'structure_id' => $draft->structureId,
                'input_snapshot' => $draft->inputSnapshot, 'trace' => $draft->trace, 'warnings' => $draft->warnings, 'errors' => $draft->errors, 'checksum' => $checksum,
            ]);
            foreach ($components as $i => $c) {
                PayrollResultComponent::create([
                    'result_id' => $result->id, 'component_id' => $c['component_id'] ?? null, 'component_code' => $c['component_code'], 'component_version' => $c['component_version'] ?? null,
                    'name' => $c['name'], 'kind' => $c['kind'], 'sequence' => $c['sequence'] ?? $i, 'amount' => $c['amount'], 'taxable_amount' => $c['taxable_amount'] ?? null,
                    'quantity' => $c['quantity'] ?? null, 'formula' => $c['formula'] ?? null, 'formula_version' => $c['formula_version'] ?? null, 'origin' => $c['origin'] ?? 'compensation',
                    'source_input_id' => $c['source_input_id'] ?? null, 'source_ref' => $c['source_ref'] ?? null, 'payslip_visible' => $c['payslip_visible'] ?? true,
                    'gl_code' => $c['gl_code'] ?? null, 'trace' => $c['trace'] ?? null,
                ]);
            }
            if ($current) {
                $current->update(['is_current' => false, 'superseded_by_id' => $result->id]);
            }
            $runEmployee->update(['current_result_id' => $result->id, 'status' => $draft->errors ? 'failed' : ($draft->warnings ? 'warning' : 'calculated'), 'attempts' => $runEmployee->attempts + 1,
                'last_error' => $draft->errors ? json_encode($draft->errors) : null]);

            return $result;
        });
    }

    public function current(int $runEmployeeId): ?PayrollCalculationResult
    {
        return PayrollCalculationResult::where('run_employee_id', $runEmployeeId)->where('is_current', true)->first();
    }

    public function verify(PayrollCalculationResult $result): bool
    {
        $rows = DB::table('payroll_result_components')->where('tenant_id', $result->tenant_id)->where('result_id', $result->id)->orderBy('sequence')->orderBy('id')->get();
        $components = $rows->map(fn ($r) => [
            'component_code' => $r->component_code, 'name' => $r->name, 'kind' => $r->kind, 'amount' => Decimal::fromDb($r->amount, 4), 'component_id' => $r->component_id, 'component_version' => $r->component_version,
            'taxable_amount' => Decimal::fromDb($r->taxable_amount, 4), 'quantity' => Decimal::fromDb($r->quantity, 6), 'formula' => $r->formula,
            'formula_version' => $r->formula_version, 'origin' => $r->origin, 'source_input_id' => $r->source_input_id, 'source_ref' => $r->source_ref, 'sequence' => (int) $r->sequence,
        ])->all();
        $draft = new ResultDraft($result->run_employee_id, $result->currency, $components, $result->engine_version, $result->payable_days === null ? null : Decimal::str($result->payable_days, 4),
            $result->working_days === null ? null : Decimal::str($result->working_days, 4), $result->country_pack_version_id, $result->compensation_id, $result->structure_id, $result->input_snapshot ?? []);

        return hash_equals($result->checksum, Checksum::of($this->fingerprint($draft, $components)));
    }

    /**
     * Totals derived from components (positive magnitudes, kind decides direction):
     *   gross = earnings; total_deductions = deduction + employee_contribution; total_taxes = tax; reimbursements; benefits;
     *   employer_contributions; net = gross + reimbursements - total_deductions - total_taxes.
     * Public and static so simulators compute totals with exactly the same rules without persisting anything.
     *
     * @param  array<int,array<string,mixed>>  $components
     * @return array<string,string>
     */
    public static function totals(array $components, string $currency): array
    {
        $sum = fn (array $kinds) => Money::sum(array_map(fn ($c) => Money::of($c['amount'], $currency), array_values(array_filter($components, fn ($c) => in_array($c['kind'], $kinds, true)))), $currency);
        $gross = $sum(PayrollStates::GROSS_KINDS);
        $deductions = $sum(['deduction', 'employee_contribution']);
        $taxes = $sum(['tax']);
        $reimb = $sum(['reimbursement']);
        $net = $gross->plus($reimb)->minus($deductions)->minus($taxes);

        return [
            'gross' => $gross->toColumn(), 'total_deductions' => $deductions->toColumn(), 'total_taxes' => $taxes->toColumn(), 'reimbursements' => $reimb->toColumn(),
            'benefits' => $sum(['benefit'])->toColumn(), 'employer_contributions' => $sum(PayrollStates::EMPLOYER_COST_KINDS)->toColumn(), 'net' => $net->toColumn(),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function normalize(ResultDraft $draft): array
    {
        $exp = Money::exponent($draft->currency);
        $out = [];
        foreach (array_values($draft->components) as $i => $c) {
            foreach (['component_code', 'name', 'kind', 'amount'] as $required) {
                if (! isset($c[$required]) || $c[$required] === '') {
                    throw ResultRejected::invalid("Result component #$i is missing \"$required\".");
                }
            }
            if (! in_array($c['kind'], PayrollStates::COMPONENT_KINDS, true)) {
                throw ResultRejected::invalid('Unknown component kind "'.$c['kind'].'".');
            }
            Decimal::guard($c['amount']);
            $amount = Decimal::of($c['amount']);
            if ($amount->isNegative()) {
                throw ResultRejected::invalid('Component amounts are positive magnitudes; the kind decides direction ('.$c['component_code'].').');
            }
            if ($amount->getScale() > $exp && ! Decimal::eq($amount->toScale($exp, \Brick\Math\RoundingMode::DOWN), $amount)) {
                throw ResultRejected::invalid('Component '.$c['component_code'].' has more than '.$exp.' decimals; round it explicitly before recording.');
            }
            $c['amount'] = Decimal::str($amount, 4);
            foreach (['taxable_amount' => 4, 'quantity' => 6] as $key => $scale) {
                if (isset($c[$key]) && $c[$key] !== '') {
                    Decimal::guard($c[$key]);
                    $c[$key] = Decimal::str($c[$key], $scale);
                } else {
                    $c[$key] = null;
                }
            }
            $c['sequence'] = (int) ($c['sequence'] ?? $i);
            $out[] = $c;
        }
        usort($out, fn ($a, $b) => $a['sequence'] <=> $b['sequence']);

        return $out;
    }

    /** @param array<int,array<string,mixed>> $components */
    private function fingerprint(ResultDraft $draft, array $components): array
    {
        return [
            'currency' => $draft->currency, 'engine' => $draft->engineVersion, 'payable_days' => $draft->payableDays === null ? null : Decimal::str($draft->payableDays, 4), 'working_days' => $draft->workingDays === null ? null : Decimal::str($draft->workingDays, 4),
            'pack_version' => $draft->countryPackVersionId, 'compensation' => $draft->compensationId, 'structure' => $draft->structureId, 'inputs' => $draft->inputSnapshot,
            'components' => array_map(fn ($c) => [
                'code' => $c['component_code'], 'name' => $c['name'], 'kind' => $c['kind'], 'amount' => $c['amount'], 'component_id' => $c['component_id'] ?? null, 'component_version' => $c['component_version'] ?? null,
                'taxable' => $c['taxable_amount'] ?? null, 'quantity' => $c['quantity'] ?? null, 'formula' => $c['formula'] ?? null, 'formula_version' => $c['formula_version'] ?? null,
                'origin' => $c['origin'] ?? 'compensation', 'input' => $c['source_input_id'] ?? null, 'ref' => $c['source_ref'] ?? null, 'sequence' => $c['sequence'],
            ], $components),
        ];
    }
}
