<?php

namespace App\Domain\Payroll\Engine;

use App\Domain\Payroll\Contracts\CompensationResolver;
use App\Domain\Payroll\Contracts\PackResolver;
use App\Domain\Payroll\Loans\LoanService;
use App\Domain\Payroll\Models\CountryPack;
use App\Domain\Payroll\Models\EmployeeBenefitEnrollment;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\PayrollSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gathers everything the pure calculator needs for ONE run employee (the only place that reads the database for a calculation).
 * Resolvers owned by workstream A are resolved lazily so this class works with fake bindings.
 */
final class RequestBuilder
{
    private const PERIODS_IN_YEAR = ['weekly' => 52, 'biweekly' => 26, 'semi_monthly' => 24, 'monthly' => 12, 'four_weekly' => 13, 'custom' => 12];

    /** @return array<string,mixed> */
    public function build(PayrollRun $run, PayrollRunEmployee $re): array
    {
        $run->loadMissing('period', 'group');
        $period = $run->period;
        $start = substr((string) $period->period_start, 0, 10);
        $end = substr((string) $period->period_end, 0, 10);
        $snap = $re->employment_snapshot ?? [];
        $payFrom = $snap['pay_from'] ?? $start;
        $payTo = $snap['pay_to'] ?? $end;
        $settings = $run->settings_snapshot ?? PayrollSettings::all();

        if (! app()->bound(CompensationResolver::class)) {
            throw new RuntimeException('The compensation resolver is not available yet.');
        }
        $segments = [];
        $current = null;
        foreach (app(CompensationResolver::class)->between($re->employee_id, $payFrom, $payTo) as $comp) {
            $from = max(substr((string) $comp['effective_from'], 0, 10), $payFrom);
            $to = min(! empty($comp['effective_to']) ? substr((string) $comp['effective_to'], 0, 10) : $payTo, $payTo);
            $comp['lines'] = $this->enrich($comp['lines'] ?? []);
            $segments[] = ['from' => $from, 'to' => $to, 'compensation' => $comp];
            $current = $comp;
        }
        usort($segments, fn ($a, $b) => $a['from'] <=> $b['from']);

        $inputs = DB::table('payroll_inputs')->where('tenant_id', $run->tenant_id)->where('employee_id', $re->employee_id)->where('period_id', $period->id)
            ->where(fn ($q) => $q->where('status', 'approved')->orWhere(fn ($w) => $w->where('status', 'consumed')->whereIn('consumed_run_id', array_filter([$run->id, $run->parent_run_id]))))->orderBy('id')->get()
            ->map(fn ($i) => ['id' => (int) $i->id, 'source' => $i->source, 'kind' => $i->kind, 'component_code' => $i->component_code, 'quantity' => Decimal::fromDb($i->quantity, 6), 'amount' => Decimal::fromDb($i->amount, 4),
                'source_ref' => $i->source_ref, 'is_final' => (bool) $i->is_final, 'meta' => $i->meta ? json_decode($i->meta, true) : null])->all();

        $adjustments = DB::table('payroll_adjustments')->where('tenant_id', $run->tenant_id)->where('employee_id', $re->employee_id)->whereIn('status', ['approved', 'applied'])
            ->where(fn ($q) => $q->where('run_id', $run->id)->orWhere(fn ($w) => $w->whereNull('run_id')->where('settlement_period_id', $period->id)))->orderBy('id')->get()
            ->map(fn ($a) => ['id' => (int) $a->id, 'kind' => $a->kind, 'component_code' => $a->component_code, 'amount' => Decimal::fromDb($a->amount, 4), 'trace' => $a->trace ? json_decode($a->trace, true) : null])->all();

        $loans = [];
        if ($run->run_type === 'regular') {
            foreach (app(LoanService::class)->dueFor($re->employee_id, $end) as $due) {
                $loans[] = ['id' => (int) ($due['installment_id'] ?? 0), 'amount' => Decimal::str($due['amount'], 4), 'component_code' => $due['component_code'], 'reference' => $due['reference']];
            }
        }

        $benefits = [];
        if ($run->run_type === 'regular') {
            $benefits = EmployeeBenefitEnrollment::query()->with('plan')
                ->where('employee_id', $re->employee_id)
                ->whereIn('status', ['active', 'termination_pending', 'ended'])
                ->whereDate('effective_from', '<=', $end)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))
                ->orderBy('id')->get()
                ->map(fn ($enrollment) => [
                    'enrollment_uuid' => $enrollment->uuid,
                    'plan_code' => $enrollment->plan->code,
                    'plan_name' => $enrollment->plan->name,
                    'component_code' => $enrollment->plan->component_code,
                    'employee_contribution' => (string) ($enrollment->employee_contribution ?? '0'),
                    'employer_contribution' => (string) ($enrollment->employer_contribution ?? '0'),
                    'effective_from' => substr((string) $enrollment->effective_from, 0, 10),
                    'effective_to' => $enrollment->effective_to ? substr((string) $enrollment->effective_to, 0, 10) : null,
                ])->all();
        }

        return ['run_employee_id' => $re->id, 'employee_id' => $re->employee_id, 'group_id' => $run->payroll_group_id, 'period_start' => $start, 'period_end' => $end, 'currency' => $run->currency,
            'run_type' => $run->run_type, 'engine_version' => $settings['calculation']['engine_version'] ?? PayrollSettings::ENGINE_VERSION, 'rounding' => ['mode' => $settings['calculation']['rounding_mode'] ?? 'half_up'],
            'days_basis' => $run->group->settings['payable_day_basis'] ?? null, 'employment' => ['start' => $payFrom, 'end' => $payTo], 'compensation' => $current, 'segments' => $segments,
            'inputs' => $inputs, 'adjustments' => $adjustments, 'loan_installments' => $loans, 'benefit_enrollments' => $benefits, 'statutory' => $this->statutory($run, $re, $end), 'overtime' => ['multiplier' => $run->group->settings['overtime_multiplier'] ?? '1']];
    }

    /** @param array<int,array<string,mixed>> $lines @return array<int,array<string,mixed>> */
    private function enrich(array $lines): array
    {
        $metas = DB::table('salary_components')->whereIn('id', array_filter(array_column($lines, 'component_id')) ?: [0])->get()->keyBy('id');
        foreach ($lines as &$l) {
            $c = $metas[$l['component_id'] ?? 0] ?? null;
            $l['meta'] = ($l['meta'] ?? []) + ($c ? ['proration' => $c->proration, 'attendance_dependent' => (bool) $c->attendance_dependent, 'lop_dependent' => (bool) $c->lop_dependent, 'min_amount' => $c->min_amount,
                'max_amount' => $c->max_amount, 'rounding_mode' => $c->rounding_mode, 'rounding_scale' => $c->rounding_scale, 'rounding_increment' => $c->rounding_increment, 'tax_classification' => $c->tax_classification,
                'statutory_mapping' => $c->statutory_mapping, 'payslip_visible' => (bool) $c->payslip_visible, 'gl_code' => $c->gl_code] : []);
        }

        return $lines;
    }

    /** @return array<string,mixed>|null */
    private function statutory(PayrollRun $run, PayrollRunEmployee $re, string $end): ?array
    {
        if (! app()->bound(PackResolver::class)) {
            return null;
        }
        $version = app(PackResolver::class)->forGroup($run->payroll_group_id, $end);
        if (! $version) {
            return null;
        }
        $pack = CountryPack::find($version->pack_id);
        [$m, $d] = array_map('intval', explode('-', $version->tax_year_start ?: '01-01'));
        $date = CarbonImmutable::parse($end);
        $yearStart = $date->setDate($date->year, $m, $d);
        if ($yearStart->gt($date)) {
            $yearStart = $yearStart->subYear();
        }
        $label = $yearStart->year.($m === 1 && $d === 1 ? '' : '-'.($yearStart->year + 1));
        $periodIndex = 1 + (int) DB::table('payroll_periods')->where('tenant_id', $run->tenant_id)->where('payroll_group_id', $run->payroll_group_id)
            ->where('period_start', '>=', $yearStart->toDateString())->where('period_end', '<', substr((string) $run->period->period_start, 0, 10))->count();
        $ytd = DB::table('payroll_calculation_results as r')->join('payroll_runs as pr', 'pr.id', '=', 'r.run_id')->join('payroll_periods as p', 'p.id', '=', 'pr.period_id')
            ->where('r.tenant_id', $run->tenant_id)->where('r.employee_id', $re->employee_id)->where('r.is_current', true)->whereIn('pr.status', ['locked', 'outputs', 'payment', 'closed'])
            ->where('p.period_start', '>=', $yearStart->toDateString())->where('p.period_end', '<', substr((string) $run->period->period_start, 0, 10))->get(['r.gross', 'r.total_taxes', 'r.net']);
        $sum = fn (string $col) => Decimal::str(Decimal::sum($ytd->map(fn ($r) => Decimal::fromDb($r->$col, 4))->all()), 4);

        return ['handler' => $pack->handler, 'country' => $pack->country, 'rules' => $version->calculation_rules ?? [], 'validation_rules' => $version->validation_rules ?? [], 'pack_version_id' => $version->id,
            'pack_version' => (int) $version->version, 'tax_year' => $label, 'periods_in_year' => self::PERIODS_IN_YEAR[$run->group->frequency] ?? 12, 'period_index' => $periodIndex,
            'ytd' => ['gross' => $sum('gross'), 'tax' => $sum('total_taxes'), 'net' => $sum('net')], 'employee' => ['worker_type' => $run->group->worker_type], 'declaration' => []];
    }
}
