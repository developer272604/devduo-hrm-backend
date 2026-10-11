<?php

namespace App\Domain\Payroll\Payslips;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\SelfService\Own;
use App\Domain\Payroll\Support\Checksum;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\PayrollEvents;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Immutable payslip snapshots built from LOCKED payroll results. A correction creates version n+1 (the previous version is kept as
 * "superseded" and stays visible to the employee only if it was published). Snapshots are encrypted at rest; every view/download is logged.
 */
final class PayslipService
{
    public const LOCKED_RUN = ['locked', 'outputs', 'payment', 'closed'];

    /** @return array{created:int,skipped:int,payslips:array<int,Payslip>} */
    public function generateForRun(PayrollRun $run, ?User $actor): array
    {
        if (! in_array($run->status, self::LOCKED_RUN, true)) {
            throw ValidationException::withMessages(['run' => 'Payslips can only be generated from a locked payroll run.']);
        }
        $created = 0;
        $skipped = 0;
        $made = [];
        $results = PayrollCalculationResult::where('run_id', $run->id)->where('is_current', true)->whereIn('status', ['ok', 'warning'])->orderBy('employee_id')->get();
        foreach ($results as $result) {
            $payslip = DB::transaction(function () use ($run, $result, $actor) {
                Employee::whereKey($result->employee_id)->lockForUpdate()->first();
                $latest = Payslip::where('run_id', $run->id)->where('employee_id', $result->employee_id)->orderByDesc('version')->first();
                if ($latest && $latest->result_id === $result->id && $latest->status !== 'withdrawn') {
                    return null;
                }
                $snapshot = $this->snapshot($run, $result);
                $slip = Payslip::create(['employee_id' => $result->employee_id, 'run_id' => $run->id, 'result_id' => $result->id, 'period_id' => $run->period_id, 'version' => ($latest?->version ?? 0) + 1,
                    'status' => 'generated', 'supersedes_id' => $latest?->id, 'snapshot' => $snapshot, 'checksum' => Checksum::of($snapshot), 'layout' => strtolower($snapshot['country'] ?? 'default'),
                    'generated_by' => $actor?->id, 'generated_at' => now()]);
                if ($latest && $latest->status === 'generated') {
                    $latest->update(['status' => 'superseded']);
                }
                $this->log($slip, $actor, 'generated');

                return $slip;
            });
            if ($payslip) {
                $created++;
                $made[] = $payslip;
            } else {
                $skipped++;
            }
        }
        if ($created) {
            Audit::record('payslips.generated', 'payroll', $run->uuid, ['count' => $created]);
            app(DomainEvents::class)->record(PayrollEvents::PAYSLIP_GENERATED, ['summary' => 'Payslips generated', 'payload' => ['run_uuid' => $run->uuid, 'count' => $created], 'subject' => ['payroll_run', $run->id]]);
        }

        return ['created' => $created, 'skipped' => $skipped, 'payslips' => $made];
    }

    private function snapshot(PayrollRun $run, PayrollCalculationResult $result): array
    {
        $employee = Employee::with('person')->findOrFail($result->employee_id);
        $period = DB::table('payroll_periods')->where('id', $run->period_id)->first();
        $group = DB::table('payroll_groups')->where('id', $run->payroll_group_id)->first();
        $settings = PayrollSettings::get('payslips');
        $lines = ['earnings' => [], 'reimbursements' => [], 'deductions' => [], 'taxes' => [], 'employee_contributions' => [], 'employer_contributions' => []];
        $map = ['earning' => 'earnings', 'reimbursement' => 'reimbursements', 'deduction' => 'deductions', 'tax' => 'taxes', 'employee_contribution' => 'employee_contributions', 'employer_contribution' => 'employer_contributions'];
        foreach ($result->components()->where('payslip_visible', true)->orderBy('sequence')->orderBy('id')->get() as $c) {
            if (! isset($map[$c->kind])) {
                continue;
            }
            $lines[$map[$c->kind]][] = ['code' => $c->component_code, 'name' => $c->name, 'amount' => Decimal::str($c->amount, 4)];
        }
        if (! ($settings['show_employer_contributions'] ?? false)) {
            $lines['employer_contributions'] = [];
        }
        $bank = DB::table('employee_bank_accounts')->where('tenant_id', Own::tid())->where('person_id', $employee->person_id)->where('is_primary', true)->where('status', 'active')->first();

        return [
            'employee' => ['uuid' => $employee->uuid, 'number' => $employee->employee_number, 'name' => $employee->person->display_name, 'joined_on' => $employee->joined_on?->format('Y-m-d')],
            'period' => ['label' => $period->label, 'start' => substr((string) $period->period_start, 0, 10), 'end' => substr((string) $period->period_end, 0, 10)],
            'run' => ['uuid' => $run->uuid, 'type' => $run->run_type], 'country' => $group->country ?? null, 'currency' => $result->currency, 'payable_days' => $result->payable_days, 'working_days' => $result->working_days,
            'lines' => $lines,
            'totals' => ['gross' => Decimal::str($result->gross, 4), 'deductions' => Decimal::str($result->total_deductions, 4), 'taxes' => Decimal::str($result->total_taxes, 4), 'reimbursements' => Decimal::str($result->reimbursements, 4), 'net' => Decimal::str($result->net, 4)],
            'ytd' => ($settings['show_ytd'] ?? true) ? $this->ytd($result, substr((string) $period->period_start, 0, 4), substr((string) $period->period_end, 0, 10)) : null,
            'bank' => $bank ? ['bank_name' => $bank->bank_name, 'account' => $bank->account_last4 ? '****'.$bank->account_last4 : null] : null,
            'branding' => $settings['branding'] ?? [],
            'result_version' => $result->version, 'result_checksum' => $result->checksum,
        ];
    }

    /** Year-to-date (calendar year of the period start) from current results of locked runs up to and including this period. */
    private function ytd(PayrollCalculationResult $result, string $year, string $periodEnd): array
    {
        $rows = PayrollCalculationResult::where('employee_id', $result->employee_id)->where('is_current', true)->whereIn('status', ['ok', 'warning'])
            ->whereIn('run_id', DB::table('payroll_runs as r')->join('payroll_periods as p', 'p.id', '=', 'r.period_id')->where('r.tenant_id', Own::tid())->whereIn('r.status', self::LOCKED_RUN)
                ->whereBetween('p.period_start', [$year.'-01-01', $year.'-12-31'])->where('p.period_end', '<=', $periodEnd)->select('r.id'))->get();
        $sum = fn (string $f) => Decimal::str(Decimal::sum($rows->pluck($f)->all()), 4);

        return ['gross' => $sum('gross'), 'deductions' => $sum('total_deductions'), 'taxes' => $sum('total_taxes'), 'net' => $sum('net'), 'periods' => $rows->count()];
    }

    private function releaseAllowed(Payslip $p): void
    {
        $run = PayrollRun::findOrFail($p->run_id);
        if (! in_array($run->status, self::LOCKED_RUN, true)) {
            throw ValidationException::withMessages(['payslip' => 'Payslips are released only after the payroll run is locked.']);
        }
        if (PayrollSettings::get('payslips.publish_on') === 'payment') {
            $paid = DB::table('payment_batches')->where('tenant_id', Own::tid())->where('run_id', $run->id)->whereIn('status', ['paid', 'partially_paid'])->exists();
            if (! $paid) {
                throw ValidationException::withMessages(['payslip' => 'Your release rule publishes payslips only after payment is confirmed.']);
            }
        }
    }

    public function publish(Payslip $p, User $actor): Payslip
    {
        return DB::transaction(function () use ($p, $actor) {
            $p = Payslip::whereKey($p->id)->lockForUpdate()->firstOrFail();
            if ($p->status === 'published') {
                return $p;
            }
            if ($p->status !== 'generated') {
                throw ValidationException::withMessages(['payslip' => 'Only a generated payslip version can be published.']);
            }
            $this->releaseAllowed($p);
            Payslip::where('run_id', $p->run_id)->where('employee_id', $p->employee_id)->where('status', 'published')->where('id', '!=', $p->id)->update(['status' => 'superseded']);
            $p->update(['status' => 'published', 'published_at' => now()]);
            $this->log($p, $actor, 'published');
            $employee = Employee::with('person')->find($p->employee_id);
            if ($employee?->person?->user_id) {
                app(NotificationService::class)->send($employee->person->user_id, 'Payslip available', 'Your payslip for '.($p->snapshot['period']['label'] ?? 'the latest period').' is ready.', 'payroll', '/payroll/me/payslips');
            }
            Audit::record('payslip.published', 'payroll', $p->uuid);

            return $p;
        });
    }

    public function publishRun(PayrollRun $run, User $actor): int
    {
        $n = 0;
        foreach (Payslip::where('run_id', $run->id)->where('status', 'generated')->get() as $p) {
            $this->publish($p, $actor);
            $n++;
        }

        return $n;
    }

    public function withdraw(Payslip $p, User $actor, string $reason): Payslip
    {
        $p->update(['status' => 'withdrawn', 'withdrawn_at' => now(), 'withdraw_reason' => $reason, 'withdrawn_by' => $actor->id]);
        $this->log($p, $actor, 'withdrawn');
        Audit::record('payslip.withdrawn', 'payroll', $p->uuid, ['reason' => $reason]);

        return $p;
    }

    public function log(Payslip $p, ?User $user, string $action): void
    {
        DB::table('payslip_access_logs')->insert(['tenant_id' => Own::tid(), 'payslip_id' => $p->id, 'employee_id' => $p->employee_id, 'user_id' => $user?->id, 'action' => $action, 'ip' => request()?->ip(), 'created_at' => now()]);
    }

    public function summary(Payslip $p, bool $withSnapshot = false): array
    {
        $s = $p->snapshot;
        $out = ['uuid' => $p->uuid, 'version' => $p->version, 'status' => $p->status, 'period' => $s['period'] ?? null, 'currency' => $s['currency'] ?? null, 'net' => $s['totals']['net'] ?? null,
            'published_at' => $p->published_at?->toIso8601String(), 'generated_at' => $p->generated_at?->toIso8601String(), 'first_viewed_at' => $p->first_viewed_at?->toIso8601String(), 'checksum' => $p->checksum];
        if ($withSnapshot) {
            $out['snapshot'] = $s;
        }

        return $out;
    }

    /** HTML with an embedded print stylesheet (no PDF library). Everything is escaped. */
    public function html(Payslip $p): string
    {
        $s = $p->snapshot;
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cur = $s['currency'] ?? '';
        $rows = function (array $lines) use ($e) {
            $h = '';
            foreach ($lines as $l) {
                $h .= '<tr><td>'.$e($l['name']).'</td><td class="n">'.$e($l['amount']).'</td></tr>';
            }

            return $h ?: '<tr><td colspan="2" class="m">None</td></tr>';
        };
        $brand = $s['branding'] ?? [];
        $sections = [['Earnings', $s['lines']['earnings']], ['Reimbursements', $s['lines']['reimbursements']], ['Deductions', array_merge($s['lines']['deductions'], $s['lines']['employee_contributions'])], ['Taxes', $s['lines']['taxes']]];
        if ($s['lines']['employer_contributions']) {
            $sections[] = ['Employer contributions (not part of net pay)', $s['lines']['employer_contributions']];
        }
        $body = '';
        foreach ($sections as [$title, $lines]) {
            $body .= '<h2>'.$e($title).'</h2><table>'.$rows($lines).'</table>';
        }
        $ytd = $s['ytd'] ? '<h2>Year to date</h2><table><tr><td>Gross</td><td class="n">'.$e($s['ytd']['gross']).'</td></tr><tr><td>Deductions</td><td class="n">'.$e($s['ytd']['deductions']).'</td></tr><tr><td>Taxes</td><td class="n">'.$e($s['ytd']['taxes']).'</td></tr><tr><td>Net</td><td class="n">'.$e($s['ytd']['net']).'</td></tr></table>' : '';
        $bank = $s['bank'] ? '<p class="m">Paid to '.$e($s['bank']['bank_name']).' '.$e($s['bank']['account']).'</p>' : '';
        $status = $p->status === 'superseded' ? '<p class="warn">This version was superseded by a corrected payslip.</p>' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Payslip '.$e($s['period']['label']).'</title><style>'
            .'body{font:14px/1.5 system-ui,sans-serif;margin:24px;color:#111}h1{font-size:20px;margin:0}h2{font-size:14px;margin:18px 0 4px;border-bottom:1px solid #999}table{width:100%;border-collapse:collapse}td{padding:3px 0}.n{text-align:right;font-variant-numeric:tabular-nums}.m{color:#555}.warn{color:#a00}.net{font-size:18px;font-weight:700;margin-top:16px;display:flex;justify-content:space-between}'
            .'@media print{body{margin:0}@page{size:A4;margin:16mm}}</style></head><body><h1>Payslip - '.$e($s['period']['label']).' (v'.$e($p->version).')</h1>'
            .($brand['footer'] ?? null ? '' : '').'<p>'.$e($s['employee']['name']).' &middot; '.$e($s['employee']['number']).'<br><span class="m">'.$e($s['period']['start']).' to '.$e($s['period']['end']).' &middot; Payable days '.$e($s['payable_days'] ?? '-').'</span></p>'
            .$status.$body.'<div class="net"><span>Net pay</span><span>'.$e($cur).' '.$e($s['totals']['net']).'</span></div>'.$ytd.$bank
            .($brand['footer'] ?? null ? '<p class="m">'.$e($brand['footer']).'</p>' : '').'</body></html>';
    }
}
