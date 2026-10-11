<?php

namespace App\Domain\Payroll\Runs;

use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Control totals over the CURRENT result of every included employee (decimal-exact; reconciles net = gross + reimbursements - deductions - taxes). */
final class ControlTotals
{
    /** @return array<string,mixed> */
    public function compute(PayrollRun $run): array
    {
        $rows = DB::table('payroll_calculation_results')->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->where('is_current', true)->get();
        $sum = fn (string $col) => Decimal::str(Decimal::sum($rows->map(fn ($r) => Decimal::fromDb($r->$col, 4))->all()), 4);
        $status = DB::table('payroll_run_employees')->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $t = ['currency' => $run->currency, 'employees' => (int) $status->sum(), 'calculated' => (int) ($status['calculated'] ?? 0) + (int) ($status['warning'] ?? 0), 'failed' => (int) ($status['failed'] ?? 0),
            'excluded' => (int) ($status['excluded'] ?? 0), 'held' => (int) ($status['held'] ?? 0), 'gross' => $sum('gross'), 'total_deductions' => $sum('total_deductions'), 'total_taxes' => $sum('total_taxes'),
            'reimbursements' => $sum('reimbursements'), 'employer_contributions' => $sum('employer_contributions'), 'benefits' => $sum('benefits'), 'net' => $sum('net')];
        $expected = Decimal::sub(Decimal::add($t['gross'], $t['reimbursements']), Decimal::add($t['total_deductions'], $t['total_taxes']));
        $t['payment_total'] = Decimal::str(Decimal::sum($rows->filter(fn ($r) => Decimal::cmp(Decimal::fromDb($r->net, 4), 0) > 0)->map(fn ($r) => Decimal::fromDb($r->net, 4))->all()), 4);
        $t['employer_cost'] = Decimal::str(Decimal::add(Decimal::add($t['gross'], $t['employer_contributions']), $t['reimbursements']), 4);
        $t['reconciled'] = Decimal::eq($expected, $t['net']);

        return $t;
    }
}
