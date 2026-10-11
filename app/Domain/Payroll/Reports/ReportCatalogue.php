<?php

namespace App\Domain\Payroll\Reports;

use App\Domain\Payroll\Explainer\DifferenceView;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Operations\PayrollOps;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Models\User;

/**
 * Payroll reports: `register` (per run) and `variance` (run vs previous run). Tenant + DataScope (payroll.reports.view) limited, read from stored
 * results only. Amount columns are shown only to holders of payroll.results.view; everyone else (including export-only users) gets "***".
 */
final class ReportCatalogue
{
    public const PERMISSIONS = ['payroll.reports.view'];

    public const MASK = '***';

    public function __construct(private DifferenceView $diff) {}

    /** @return array<int,array<string,mixed>> */
    public function catalogue(): array
    {
        return [
            ['key' => 'register', 'title' => 'Payroll register', 'filters' => ['run (required)'], 'columns' => ['employee_number', 'name', 'status', 'currency', 'gross', 'deductions', 'taxes', 'reimbursements', 'employer_contributions', 'net', 'version']],
            ['key' => 'variance', 'title' => 'Payroll variance vs previous run', 'filters' => ['run (required)', 'against'], 'columns' => ['employee_number', 'name', 'currency', 'previous_net', 'current_net', 'difference', 'percent', 'drivers']],
        ];
    }

    public function exists(string $key): bool
    {
        return in_array($key, ['register', 'variance'], true);
    }

    /** @return array{columns:array<int,string>,rows:\Generator} */
    public function stream(User $user, string $key, array $filters): \Generator
    {
        abort_unless($this->exists($key), 404, 'That report does not exist.');
        abort_unless(PayrollOps::any($user, self::PERMISSIONS), 403, 'You do not have access to payroll reports.');
        $run = PayrollRun::where('uuid', $filters['run'] ?? '')->first();
        abort_unless($run, 422, 'Choose a payroll run (filter "run").');
        $money = $user->allows('payroll.results.view');
        $scale = Money::exponent($run->currency);
        $scoped = PayrollOps::employees($user, self::PERMISSIONS)->pluck('e.id')->map(fn ($v) => (int) $v)->all() ?: [0];
        $people = PayrollOps::employees($user, self::PERMISSIONS)->whereIn('e.id', $scoped)->get(['e.id', 'e.employee_number', 'p.display_name'])->keyBy('id');
        $m = fn ($v) => $money ? Decimal::str($v, $scale) : self::MASK;
        $against = null;
        if ($key === 'variance') {
            $against = isset($filters['against']) ? PayrollRun::where('uuid', $filters['against'])->first()
                : PayrollRun::where('payroll_group_id', $run->payroll_group_id)->where('id', '!=', $run->id)->where('status', '!=', 'cancelled')
                    ->whereHas('period', fn ($p) => $p->where('period_end', '<', substr((string) $run->period->getRawOriginal('period_start'), 0, 10)))->orderByDesc('id')->first();
        }
        foreach (PayrollCalculationResult::where('run_id', $run->id)->where('is_current', true)->whereIn('employee_id', $scoped)->orderBy('employee_id')->cursor() as $r) {
            $p = $people[$r->employee_id] ?? null;
            if ($key === 'register') {
                yield ['employee_number' => $p->employee_number ?? null, 'name' => $p->display_name ?? null, 'status' => $r->status, 'currency' => $r->currency, 'gross' => $m($r->gross), 'deductions' => $m($r->total_deductions), 'taxes' => $m($r->total_taxes),
                    'reimbursements' => $m($r->reimbursements), 'employer_contributions' => $m($r->employer_contributions), 'net' => $m($r->net), 'version' => $r->version];

                continue;
            }
            $prev = $against ? PayrollCalculationResult::where('run_id', $against->id)->where('employee_id', $r->employee_id)->where('is_current', true)->first() : null;
            $pair = $this->diff->pair($r, $prev, (bool) ($against && ! $prev));
            $pct = ($prev && ! Decimal::isZero($prev->net)) ? Decimal::div(Decimal::mul(Decimal::sub($r->net, $prev->net), 100), $prev->net, 2)->__toString() : null;
            yield ['employee_number' => $p->employee_number ?? null, 'name' => $p->display_name ?? null, 'currency' => $r->currency, 'previous_net' => $prev ? $m($prev->net) : null, 'current_net' => $m($r->net),
                'difference' => $money ? $pair['difference'] : self::MASK, 'percent' => $money ? $pct : self::MASK, 'drivers' => $money ? implode('; ', array_map(fn ($d) => $d['driver'].' '.$d['effect'], $pair['drivers'])) : self::MASK];
        }
    }

    /** @return array<string,mixed> */
    public function page(User $user, string $key, array $filters, int $page, int $perPage): array
    {
        $rows = [];
        $total = 0;
        foreach ($this->stream($user, $key, $filters) as $row) {
            if ($total >= ($page - 1) * $perPage && count($rows) < $perPage) {
                $rows[] = $row;
            }
            $total++;
        }

        return ['report' => $key, 'data' => $rows, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total], 'amounts_masked' => ! $user->allows('payroll.results.view')];
    }
}
