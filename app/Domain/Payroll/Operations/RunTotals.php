<?php

namespace App\Domain\Payroll\Operations;

use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use Illuminate\Support\Facades\DB;

/** Control totals and reconciliation of a run, summed with exact decimals over the CURRENT result versions (read-only). */
final class RunTotals
{
    /** @return array<string,mixed> */
    public static function forRun(int $runId, string $currency): array
    {
        $t = ['gross' => '0', 'total_deductions' => '0', 'total_taxes' => '0', 'reimbursements' => '0', 'employer_contributions' => '0', 'benefits' => '0', 'net' => '0'];
        $n = 0;
        $failed = 0;
        foreach (PayrollCalculationResult::where('run_id', $runId)->where('is_current', true)->cursor() as $r) {
            $n++;
            if ($r->status === 'failed') {
                $failed++;

                continue;
            }
            foreach ($t as $k => $v) {
                $t[$k] = Decimal::add($v, $r->{$k})->__toString();
            }
        }
        $scale = Money::exponent($currency);
        $employerCost = Decimal::add(Decimal::add(Decimal::add($t['gross'], $t['reimbursements']), $t['employer_contributions']), $t['benefits'])->__toString();
        $payment = '0';
        $batches = DB::table('payment_batches')->where('run_id', $runId)->whereNotIn('status', ['failed'])->count();
        foreach (DB::table('payment_instructions as i')->join('payment_batches as b', 'b.id', '=', 'i.batch_id')->where('b.run_id', $runId)->where('i.status', '!=', 'failed')->pluck('i.amount') as $a) {
            $payment = Decimal::add($payment, Decimal::fromDb($a, 4))->__toString();
        }

        return ['currency' => $currency, 'employee_count' => $n, 'failed_count' => $failed, 'gross_total' => Decimal::str($t['gross'], $scale), 'deduction_total' => Decimal::str(Decimal::add($t['total_deductions'], $t['total_taxes']), $scale),
            'employer_cost' => Decimal::str($employerCost, $scale), 'net_total' => Decimal::str($t['net'], $scale), 'payment_total' => $batches ? Decimal::str($payment, $scale) : null,
            'reconciliation' => ['payment_batches' => $batches, 'net_equals_payment' => $batches ? Decimal::eq($t['net'], $payment) : null,
                'difference' => $batches ? Decimal::str(Decimal::sub($t['net'], $payment), $scale) : null]];
    }
}
