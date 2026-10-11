<?php

namespace App\Domain\Payroll\Simulation;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Contracts\EmployeeCalculator;
use App\Domain\Payroll\Contracts\ResultStore as ResultStoreContract;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\PayrollSimulationRun;
use App\Domain\Payroll\Operations\PayrollOps;
use App\Domain\Payroll\Results\ResultStore;
use App\Domain\Payroll\Security\SensitiveAccess;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Domain\Payroll\Support\Rounding;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Read-only what-if (spec 46/47/89). It NEVER touches live payroll: the only write is one payroll_simulation_runs record (encrypted result).
 * Baseline = the employee's latest stored current result. Scenario = Contracts\EmployeeCalculator with overrides when workstream B has bound it;
 * otherwise a documented ESTIMATE from the stored components (engine = "estimate": statutory amounts are NOT re-evaluated).
 * Kinds: salary_revision {percent}, bonus {amount}, lop {days}.
 */
final class SimulationService
{
    public const KINDS = ['salary_revision', 'bonus', 'lop'];

    public const MAX_EMPLOYEES = 100;

    public function __construct(private ResultStoreContract $results) {}

    /** @return array<string,mixed> */
    public function run(User $viewer, string $kind, array $params, array $employeeUuids, ?string $title): array
    {
        abort_unless(in_array($kind, self::KINDS, true), 422, 'Unknown simulation kind.');
        $reveal = $viewer->allows('payroll.compensation.reveal');
        $rows = PayrollOps::employees($viewer, ['payroll.simulate'])->whereIn('e.uuid', $employeeUuids)->limit(self::MAX_EMPLOYEES)->get(['e.id', 'e.uuid', 'e.employee_number', 'p.display_name']);
        abort_if($rows->isEmpty(), 404, 'None of the selected employees are in your scope.');
        $out = [];
        $skipped = [];
        $totals = ['baseline_net' => '0', 'scenario_net' => '0', 'baseline_gross' => '0', 'scenario_gross' => '0'];
        $engine = 'estimate';
        $currency = null;
        foreach ($rows as $e) {
            $re = PayrollRunEmployee::where('employee_id', $e->id)->whereNotNull('current_result_id')->whereHas('run', fn ($r) => $r->where('status', '!=', 'cancelled'))->orderByDesc('id')->first();
            $base = $re ? $this->results->current($re->id) : null;
            if (! $base) {
                $skipped[] = ['employee_uuid' => $e->uuid, 'reason' => 'No stored payroll result to use as a baseline.'];

                continue;
            }
            $currency ??= $base->currency;
            if ($currency !== $base->currency) {
                $skipped[] = ['employee_uuid' => $e->uuid, 'reason' => 'Different currency; simulate one currency at a time.'];

                continue;
            }
            $components = $base->components()->orderBy('sequence')->get()->map(fn ($c) => ['component_code' => $c->component_code, 'name' => $c->name, 'kind' => $c->kind, 'amount' => Decimal::str($c->amount, Money::exponent($base->currency)), 'origin' => $c->origin])->all();
            [$scenario, $used, $note] = $this->scenario($re, $base, $components, $kind, $params);
            $engine = $used;
            $after = ResultStore::totals($scenario, $base->currency);
            $before = ['gross' => $base->gross, 'net' => $base->net, 'total_deductions' => $base->total_deductions, 'total_taxes' => $base->total_taxes];
            foreach (['net', 'gross'] as $f) {
                $totals['baseline_'.$f] = Decimal::add($totals['baseline_'.$f], $before[$f])->__toString();
                $totals['scenario_'.$f] = Decimal::add($totals['scenario_'.$f], $after[$f])->__toString();
            }
            $out[] = ['employee_uuid' => $e->uuid, 'employee_number' => $e->employee_number, 'name' => $e->display_name, 'baseline' => ['gross' => $before['gross'], 'net' => $before['net']], 'scenario' => ['gross' => $after['gross'], 'net' => $after['net']],
                'difference' => ['gross' => Decimal::sub($after['gross'], $before['gross'])->__toString(), 'net' => Decimal::sub($after['net'], $before['net'])->__toString()],
                'net_change_percent' => Decimal::isZero($before['net']) ? null : Decimal::div(Decimal::mul(Decimal::sub($after['net'], $before['net']), 100), $before['net'], 2)->__toString(), 'negative_net' => Decimal::isNegative($after['net']), 'note' => $note];
        }
        $scale = Money::exponent($currency ?? 'USD');
        $result = ['kind' => $kind, 'params' => $params, 'engine' => $engine, 'currency' => $currency, 'mutates_live_payroll' => false,
            'limitations' => $engine === 'estimate' ? ['Estimate from stored components: taxes, contributions and other statutory amounts are not re-evaluated.'] : [],
            'employees' => $out, 'skipped' => $skipped, 'employee_count' => count($out), 'employees_with_issues' => count(array_filter($out, fn ($r) => $r['negative_net'])),
            'totals' => ['baseline_net' => Decimal::str($totals['baseline_net'], $scale), 'scenario_net' => Decimal::str($totals['scenario_net'], $scale), 'difference_net' => Decimal::str(Decimal::sub($totals['scenario_net'], $totals['baseline_net']), $scale),
                'difference_gross' => Decimal::str(Decimal::sub($totals['scenario_gross'], $totals['baseline_gross']), $scale)]];
        $record = PayrollSimulationRun::create(['kind' => $kind, 'params' => $params, 'result' => $result, 'status' => 'completed', 'created_by' => $viewer->id, 'title' => $title]);
        SensitiveAccess::log($viewer, null, 'simulation', 'view', $reveal, 'simulation', $record->uuid);
        Audit::record('payroll.simulation.created', 'payroll', $record->uuid, ['kind' => $kind, 'employees' => count($out)]);

        return $this->present($result, $record->uuid, $reveal);
    }

    /** Masked presentation: without payroll.compensation.reveal only counts and percentages are returned (amounts null). */
    public function present(array $result, string $uuid, bool $reveal): array
    {
        $result['uuid'] = $uuid;
        $result['masked'] = ! $reveal;
        if (! $reveal) {
            $result['employees'] = array_map(fn ($r) => ['baseline' => null, 'scenario' => null, 'difference' => null] + $r, $result['employees']);
            $result['totals'] = array_map(fn () => null, $result['totals']);
        }

        return $result;
    }

    /** @return array{0:array<int,array<string,mixed>>,1:string,2:?string} */
    private function scenario(PayrollRunEmployee $re, $base, array $components, string $kind, array $params): array
    {
        $calculator = PayrollOps::lazy(EmployeeCalculator::class);
        if ($calculator) {
            try {
                $run = PayrollRun::with('period')->find($re->run_id);
                $draft = $calculator->calculate(['employee_id' => $re->employee_id, 'group_id' => $re->payroll_group_id, 'period_start' => substr((string) $run->period->getRawOriginal('period_start'), 0, 10),
                    'period_end' => substr((string) $run->period->getRawOriginal('period_end'), 0, 10), 'currency' => $base->currency, 'run_type' => 'simulation', 'overrides' => [$kind => $params]]);
                if (! $draft->errors) {
                    return [$draft->components, 'employee_calculator', null];
                }
            } catch (\Throwable) {
                // fall through to the documented estimate
            }
        }
        $scale = Money::exponent($base->currency);
        $adjust = function (callable $f) use ($components, $scale) {
            return array_map(function ($c) use ($f, $scale) {
                if ($c['kind'] === 'earning' && ($c['origin'] ?? '') === 'compensation') {
                    $c['amount'] = Rounding::apply($f($c['amount']), $scale);
                }

                return $c;
            }, $components);
        };
        if ($kind === 'salary_revision') {
            $factor = Decimal::add(1, Decimal::div($params['percent'], 100, 8))->__toString();

            return [$adjust(fn ($a) => Decimal::mul($a, $factor)->__toString()), 'estimate', 'Compensation earnings scaled; statutory amounts unchanged.'];
        }
        if ($kind === 'lop') {
            $working = $base->working_days && ! Decimal::isZero($base->working_days) ? (string) $base->working_days : '30';
            $factor = Decimal::sub(1, Decimal::div($params['days'], $working, 8))->__toString();
            if (Decimal::isNegative($factor)) {
                throw ValidationException::withMessages(['days' => 'LOP days exceed the working days of the period.']);
            }

            return [$adjust(fn ($a) => Decimal::mul($a, $factor)->__toString()), 'estimate', 'Compensation earnings reduced pro rata; statutory amounts unchanged.'];
        }
        $components[] = ['component_code' => 'SIM_BONUS', 'name' => 'Simulated bonus', 'kind' => 'earning', 'amount' => Decimal::str($params['amount'], $scale), 'origin' => 'input'];

        return [$components, 'estimate', 'Bonus added as earning; statutory amounts unchanged.'];
    }
}
