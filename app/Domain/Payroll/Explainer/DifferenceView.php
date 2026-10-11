<?php

namespace App\Domain\Payroll\Explainer;

use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Operations\PayrollOps;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Difference views (spec 45/48): run vs previous run (or draft vs approved, or recalculation before/after) and employee vs previous period.
 * Drivers are derived from stored component origin/kind/input provenance; the signed driver effects always add up to the net difference (`reconciles`).
 */
final class DifferenceView
{
    private const CAP = 5000;

    /** @return array<string,mixed> */
    public function employee(User $viewer, string $employeeUuid, ?string $periodUuid, ?string $againstPeriodUuid, PayrollExplainer $explainer): array
    {
        [$employee, , $current] = $explainer->locate($viewer, $employeeUuid, null);
        $currentPeriod = PayrollRun::with('period')->find($current->run_id)->period;
        if ($periodUuid) {
            $currentPeriod = PayrollPeriod::where('uuid', $periodUuid)->firstOrFail();
            $re = PayrollRunEmployee::where('employee_id', $employee->id)->whereNotNull('current_result_id')->whereHas('run', fn ($r) => $r->where('period_id', $currentPeriod->id)->where('status', '!=', 'cancelled'))->orderByDesc('id')->first();
            abort_unless($re, 404, 'No result for that period.');
            $current = PayrollCalculationResult::find($re->current_result_id);
        }
        $prevRe = PayrollRunEmployee::where('employee_id', $employee->id)->whereNotNull('current_result_id')->whereHas('run', function ($r) use ($currentPeriod, $againstPeriodUuid) {
            $r->where('status', '!=', 'cancelled')->whereHas('period', function ($p) use ($currentPeriod, $againstPeriodUuid) {
                $againstPeriodUuid ? $p->where('uuid', $againstPeriodUuid) : $p->where('period_end', '<', substr((string) $currentPeriod->getRawOriginal('period_start'), 0, 10));
            });
        })->orderByDesc('id')->first();
        $previous = $prevRe ? PayrollCalculationResult::find($prevRe->current_result_id) : null;
        $prevPeriod = $previous ? PayrollRun::with('period')->find($previous->run_id)->period->label : null;

        return ['employee' => ['employee_uuid' => $employee->uuid, 'name' => $employee->display_name], 'current_period' => $currentPeriod->label, 'previous_period' => $prevPeriod, 'has_previous' => (bool) $previous]
            + $this->pair($current, $previous, false);
    }

    /** @return array<string,mixed> */
    public function runs(User $viewer, string $runUuid, ?string $againstUuid, string $mode, int $limit): array
    {
        $run = PayrollRun::with('period')->where('uuid', $runUuid)->firstOrFail();
        $scoped = PayrollOps::employees($viewer, ['payroll.results.view'])->pluck('e.id')->map(fn ($v) => (int) $v)->all() ?: [0];
        $against = null;
        if ($mode === 'run') {
            $against = $againstUuid ? PayrollRun::where('uuid', $againstUuid)->firstOrFail()
                : PayrollRun::where('payroll_group_id', $run->payroll_group_id)->where('id', '!=', $run->id)->where('status', '!=', 'cancelled')
                    ->whereHas('period', fn ($p) => $p->where('period_end', '<', substr((string) $run->period->getRawOriginal('period_start'), 0, 10)))->orderByDesc('id')->first();
        }
        $pairs = [];
        $joiners = 0;
        foreach (PayrollCalculationResult::where('run_id', $run->id)->where('is_current', true)->whereIn('employee_id', $scoped)->cursor() as $r) {
            if (count($pairs) >= self::CAP) {
                break;
            }
            if ($mode === 'recalculation') {
                $prev = $r->version > 1 ? PayrollCalculationResult::where('run_employee_id', $r->run_employee_id)->where('version', $r->version - 1)->first() : null;
                if (! $prev) {
                    continue;
                }
            } else {
                $prev = $against ? PayrollCalculationResult::where('run_id', $against->id)->where('employee_id', $r->employee_id)->where('is_current', true)->first() : null;
                if ($against && ! $prev) {
                    $joiners++;
                }
            }
            $pairs[] = [$r, $prev];
        }
        $leavers = 0;
        if ($mode === 'run' && $against) {
            $seen = array_map(fn ($p) => $p[0]->employee_id, $pairs);
            $leavers = PayrollCalculationResult::where('run_id', $against->id)->where('is_current', true)->whereIn('employee_id', $scoped)->whereNotIn('employee_id', $seen ?: [0])->count();
        }
        $scale = Money::exponent($run->currency);
        $drivers = [];
        $netCur = $netPrev = '0';
        $movers = [];
        foreach ($pairs as [$cur, $prev]) {
            $p = $this->pair($cur, $prev, $mode === 'run' && $against && ! $prev);
            $netCur = Decimal::add($netCur, $p['current_net'])->__toString();
            $netPrev = Decimal::add($netPrev, $p['previous_net'] ?? '0')->__toString();
            foreach ($p['drivers'] as $d) {
                $drivers[$d['driver']] = Decimal::add($drivers[$d['driver']] ?? '0', $d['effect'])->__toString();
            }
            if (! Decimal::isZero($p['difference'])) {
                $movers[] = ['employee_id' => $cur->employee_id] + $p;
            }
        }
        usort($movers, fn ($a, $b) => Decimal::cmp(Decimal::of($b['difference'])->abs(), Decimal::of($a['difference'])->abs()));
        $movers = array_slice($movers, 0, $limit);
        $people = $movers ? DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')->whereIn('e.id', array_column($movers, 'employee_id'))->get(['e.id', 'e.uuid', 'e.employee_number', 'p.display_name'])->keyBy('id') : collect();
        $diff = Decimal::sub($netCur, $netPrev);

        return ['run' => ['uuid' => $run->uuid, 'status' => $run->status, 'period' => $run->period->label], 'against' => $against ? ['uuid' => $against->uuid, 'status' => $against->status] : null, 'mode' => $mode, 'currency' => $run->currency,
            'employees_compared' => count($pairs), 'truncated' => count($pairs) >= self::CAP, 'new_joiners' => $joiners, 'leavers' => $leavers,
            'current_net_total' => Decimal::str($netCur, $scale), 'previous_net_total' => Decimal::str($netPrev, $scale), 'difference' => Decimal::str($diff, $scale),
            'drivers' => collect($drivers)->map(fn ($v, $k) => ['driver' => $k, 'effect' => Decimal::str($v, $scale)])->values()->all(),
            'reconciles' => Decimal::eq(collect($drivers)->reduce(fn ($c, $v) => Decimal::add($c, $v)->__toString(), '0'), $diff),
            'top_movers' => array_map(fn ($m) => ['employee_uuid' => $people[$m['employee_id']]->uuid ?? null, 'employee_number' => $people[$m['employee_id']]->employee_number ?? null, 'name' => $people[$m['employee_id']]->display_name ?? null,
                'difference' => $m['difference'], 'current_net' => $m['current_net'], 'previous_net' => $m['previous_net'], 'drivers' => $m['drivers']], $movers)];
    }

    /** @return array<string,mixed> current vs previous result of one employee: nets, signed effect per component, grouped drivers */
    public function pair(PayrollCalculationResult $cur, ?PayrollCalculationResult $prev, bool $joiner): array
    {
        $scale = Money::exponent($cur->currency);
        $curMap = $this->effects($cur);
        $prevMap = $prev ? $this->effects($prev) : [];
        $drivers = [];
        $lines = [];
        $payableChanged = $prev && $prev->payable_days !== null && $cur->payable_days !== null && ! Decimal::eq($prev->payable_days, $cur->payable_days);
        $compChanged = $prev && $prev->compensation_id !== $cur->compensation_id;
        foreach (array_unique(array_merge(array_keys($curMap), array_keys($prevMap))) as $code) {
            $c = $curMap[$code] ?? null;
            $p = $prevMap[$code] ?? null;
            $delta = Decimal::sub($c['effect'] ?? '0', $p['effect'] ?? '0')->__toString();
            if (Decimal::isZero($delta)) {
                continue;
            }
            $ref = $c ?? $p;
            $driver = $joiner ? 'New Joiner' : $this->driver($ref, (bool) $compChanged, (bool) $payableChanged);
            $drivers[$driver] = Decimal::add($drivers[$driver] ?? '0', $delta)->__toString();
            $lines[] = ['code' => $code, 'name' => $ref['name'], 'kind' => $ref['kind'], 'previous' => $p ? Decimal::str($p['amount'], $scale) : null, 'current' => $c ? Decimal::str($c['amount'], $scale) : null,
                'effect_on_net' => Decimal::str($delta, $scale), 'driver' => $driver];
        }
        $diff = Decimal::sub($cur->net, $prev?->net ?? '0');

        return ['currency' => $cur->currency, 'current_net' => Decimal::str($cur->net, $scale), 'previous_net' => $prev ? Decimal::str($prev->net, $scale) : null, 'difference' => Decimal::str($diff, $scale),
            'drivers' => collect($drivers)->map(fn ($v, $k) => ['driver' => $k, 'effect' => Decimal::str($v, $scale)])->values()->all(), 'components' => $lines,
            'reconciles' => Decimal::eq(collect($drivers)->reduce(fn ($a, $v) => Decimal::add($a, $v)->__toString(), '0'), $diff)];
    }

    /** @return array<string,array<string,mixed>> */
    private function effects(PayrollCalculationResult $r): array
    {
        $out = [];
        $rows = $r->components()->get();
        $inputs = DB::table('payroll_inputs')->whereIn('id', $rows->pluck('source_input_id')->filter()->all() ?: [0])->pluck('source', 'id');
        foreach ($rows as $c) {
            if ((PayrollExplainer::SIGN[$c->kind] ?? 0) === 0) {
                continue;
            }
            $k = $c->component_code;
            $out[$k] = ['code' => $k, 'name' => $c->name, 'kind' => $c->kind, 'origin' => $c->origin, 'input' => $c->source_input_id ? ($inputs[$c->source_input_id] ?? null) : null,
                'amount' => Decimal::add($out[$k]['amount'] ?? '0', $c->amount)->__toString(), 'effect' => Decimal::add($out[$k]['effect'] ?? '0', PayrollExplainer::signed($c->kind, $c->amount))->__toString()];
        }

        return $out;
    }

    private function driver(array $c, bool $compChanged, bool $payableChanged): string
    {
        $code = strtoupper($c['code']);
        if (in_array($c['origin'], ['arrear', 'retro'], true)) {
            return 'Arrear';
        }
        if ($c['origin'] === 'loan') {
            return 'Loan Recovery';
        }
        if ($c['kind'] === 'tax' || $c['origin'] === 'statutory') {
            return 'Tax Change';
        }
        if ($c['input'] === 'overtime' || str_contains($code, 'OVERTIME')) {
            return 'Overtime';
        }
        if (in_array($c['input'], ['bonus', 'commission', 'variable_pay'], true) || str_contains($code, 'BONUS')) {
            return 'Bonus';
        }
        if (str_contains($code, 'LOP') || str_contains($code, 'UNPAID')) {
            return 'LOP';
        }
        if ($c['origin'] === 'compensation') {
            return $compChanged ? 'Salary Revision' : ($payableChanged ? 'LOP' : 'Other');
        }

        return 'Other';
    }
}
