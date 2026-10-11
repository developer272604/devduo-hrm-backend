<?php

namespace App\Domain\Payroll\Validation;

use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollValidationIssue;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\PayrollSettings;
use Illuminate\Support\Facades\DB;

/**
 * Validation + variance analysis for a run. Idempotent: findings are keyed by a fingerprint (code + subject), so re-validating updates instead of
 * duplicating; findings that no longer apply are auto-resolved, waived ones stay waived. Severities: information | warning | blocking | critical.
 * "critical" is reserved for operational failures (a calculation that could not complete); data problems are "blocking".
 */
final class ValidationEngine
{
    /** @return array{open:int,blocking:int,critical:int,warnings:int} */
    public function validate(PayrollRun $run): array
    {
        $s = $run->settings_snapshot ?? PayrollSettings::all();
        $found = [];
        $add = function (string $code, string $severity, string $message, ?int $employeeId = null, array $details = [], ?array $action = null, string $subject = '') use (&$found, $run) {
            $fp = substr(sha1($code.'|'.$employeeId.'|'.$subject), 0, 40);
            $found[$fp] = ['run_id' => $run->id, 'payroll_group_id' => $run->payroll_group_id, 'period_id' => $run->period_id, 'employee_id' => $employeeId, 'code' => $code, 'severity' => $severity,
                'message' => $message, 'details' => $details ?: null, 'action' => $action, 'fingerprint' => $fp];
        };

        // run-level input checks
        $pending = DB::table('payroll_inputs')->where('tenant_id', $run->tenant_id)->where('period_id', $run->period_id)->where('status', 'pending')->count();
        if ($pending) {
            $add('unapproved_input', 'blocking', "$pending payroll input(s) are waiting for approval and were not used in this calculation.", null, ['count' => $pending], ['label' => 'Review inputs', 'href' => '/payroll/inputs?status=pending']);
        }
        $notFinal = DB::table('payroll_inputs')->where('tenant_id', $run->tenant_id)->where('period_id', $run->period_id)->whereIn('status', ['approved', 'consumed'])->where('is_final', false)->count();
        if ($notFinal) {
            $add('input_not_final', ($s['inputs']['attendance_pending_policy'] ?? 'block') === 'block' ? 'blocking' : 'warning', "$notFinal input(s) are built from attendance or leave data that is not final yet.", null, ['count' => $notFinal]);
        }
        if ($run->run_type === 'regular' && ! DB::table('payroll_inputs')->where('tenant_id', $run->tenant_id)->where('period_id', $run->period_id)->where('source', 'attendance')->exists()) {
            $add('attendance_missing', 'warning', 'No attendance input has been collected for this period.');
        }

        $previous = $this->previousNet($run);
        $warnPct = $s['validation']['variance_warn_percent'] ?? null;
        $blockPct = $s['validation']['variance_block_percent'] ?? null;
        $roster = DB::table('payroll_run_employees')->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->whereNotIn('status', ['excluded', 'held'])->get();
        $results = DB::table('payroll_calculation_results')->where('tenant_id', $run->tenant_id)->where('run_id', $run->id)->where('is_current', true)->get()->keyBy('employee_id');

        foreach ($roster as $re) {
            $r = $results[$re->employee_id] ?? null;
            if (! $r) {
                $add('missing_result', 'blocking', 'This employee has not been calculated.', $re->employee_id);

                continue;
            }
            $errors = json_decode($r->errors ?? 'null', true) ?: [];
            foreach ($errors as $e) {
                $operational = ($e['code'] ?? '') !== 'no_compensation';
                $add($e['code'] ?? 'calculation_failed', $operational ? 'critical' : 'blocking', $e['message'] ?? 'The calculation failed.', $re->employee_id, [], null, (string) ($e['code'] ?? ''));
            }
            if ($errors) {
                continue;
            }
            $net = Decimal::fromDb($r->net, 4);
            if (Decimal::isNegative($net)) {
                $add('negative_net', ($s['calculation']['negative_net'] ?? 'block') === 'block' ? 'blocking' : 'warning', 'Net pay is negative.', $re->employee_id);
            }
            if (Decimal::isZero($net) && Decimal::isZero(Decimal::fromDb($r->gross, 4)) && ($s['calculation']['zero_pay'] ?? 'warn') === 'warn') {
                $add('zero_pay', 'warning', 'Gross and net pay are zero.', $re->employee_id);
            }
            foreach (json_decode($r->warnings ?? 'null', true) ?: [] as $w) {
                if (in_array($w['code'] ?? '', ['negative_net', 'zero_pay'], true)) {
                    continue;
                }
                $add($w['code'] ?? 'calculation_warning', 'warning', $w['message'] ?? 'The calculation produced a warning.', $re->employee_id, [], null, (string) ($w['code'] ?? ''));
            }
            $prev = $previous[$re->employee_id] ?? null;
            if ($prev !== null && ! Decimal::isZero($prev)) {
                $pct = Decimal::div(Decimal::sub($net, $prev)->abs(), Decimal::of($prev)->abs(), 4)->multipliedBy(100);
                if ($blockPct !== null && Decimal::cmp($pct, $blockPct) > 0) {
                    $add('variance_high', 'blocking', 'Net pay changed by more than '.$blockPct.'% compared with the previous period.', $re->employee_id, ['percent' => Decimal::str($pct, 2)]);
                } elseif ($warnPct !== null && Decimal::cmp($pct, $warnPct) > 0) {
                    $add('variance_high', 'warning', 'Net pay changed by more than '.$warnPct.'% compared with the previous period.', $re->employee_id, ['percent' => Decimal::str($pct, 2)]);
                }
            }
        }
        $totals = $run->control_totals ?? [];
        if ($totals && ! ($totals['reconciled'] ?? true)) {
            $add('totals_not_reconciled', 'critical', 'Control totals do not reconcile with the employee results.');
        }

        return DB::transaction(function () use ($run, $found) {
            foreach ($found as $fp => $row) {
                $issue = PayrollValidationIssue::where('run_id', $run->id)->where('fingerprint', $fp)->first();
                if ($issue) {
                    $keep = in_array($issue->status, ['waived', 'acknowledged'], true) && $issue->severity !== 'critical' ? $issue->status : 'open';
                    $issue->update(['severity' => $row['severity'], 'message' => $row['message'], 'details' => $row['details'], 'status' => $keep]);
                } else {
                    PayrollValidationIssue::create($row + ['status' => 'open']);
                }
            }
            PayrollValidationIssue::where('run_id', $run->id)->where('status', 'open')->whereNotIn('fingerprint', array_keys($found) ?: ['-'])->update(['status' => 'resolved', 'resolved_at' => now(), 'resolution_note' => 'No longer applies after re-validation.']);

            return $this->summary($run);
        });
    }

    /** @return array{open:int,blocking:int,critical:int,warnings:int} */
    public function summary(PayrollRun $run): array
    {
        $open = PayrollValidationIssue::where('run_id', $run->id)->where('status', 'open');

        return ['open' => (clone $open)->count(), 'blocking' => (clone $open)->where('severity', 'blocking')->count(), 'critical' => (clone $open)->where('severity', 'critical')->count(), 'warnings' => (clone $open)->where('severity', 'warning')->count()];
    }

    /** @return array<int,string> employee_id => net of the most recent earlier locked regular run of the same group */
    private function previousNet(PayrollRun $run): array
    {
        $prev = DB::table('payroll_runs as r')->join('payroll_periods as p', 'p.id', '=', 'r.period_id')->where('r.tenant_id', $run->tenant_id)->where('r.payroll_group_id', $run->payroll_group_id)->where('r.run_type', 'regular')
            ->whereIn('r.status', ['locked', 'outputs', 'payment', 'closed'])->where('r.id', '!=', $run->id)->where('p.period_end', '<', substr((string) $run->period->period_start, 0, 10))->orderByDesc('p.period_end')->first(['r.id']);
        if (! $prev) {
            return [];
        }

        return DB::table('payroll_calculation_results')->where('run_id', $prev->id)->where('is_current', true)->get()->mapWithKeys(fn ($r) => [$r->employee_id => Decimal::fromDb($r->net, 4)])->all();
    }
}
