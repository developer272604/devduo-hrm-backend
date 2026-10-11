<?php

namespace App\Domain\Payroll\Settlement;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Payroll\PayrollContract;
use App\Domain\Payroll\Contracts\CompensationResolver;
use App\Domain\Payroll\Contracts\ResultStore;
use App\Domain\Payroll\Loans\LoanService;
use App\Domain\Payroll\Models\PayrollGroup;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\SettlementItem;
use App\Domain\Payroll\Models\SettlementRun;
use App\Domain\Payroll\Results\ResultDraft;
use App\Domain\Payroll\SelfService\Own;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Domain\Payroll\Support\PayrollEvents;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\People\Models\Employee;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Support\RequestFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Full and final settlement (foundation). Items are explicit and itemised (each with an explanation and source). Country rules stay
 * configuration: nothing here knows a statutory notice or encashment formula; the payroll team supplies days/rates or accepts the
 * Leave contract references. The approved settlement is stored in a settlement-type run through ResultStore (immutable, versioned).
 */
final class SettlementService
{
    public const KINDS = ['salary', 'leave_encashment', 'notice_pay', 'bonus', 'recovery', 'loan', 'advance', 'benefit_recovery', 'other'];

    private const PAY_COMPONENT_KIND = ['salary' => 'earning', 'leave_encashment' => 'earning', 'notice_pay' => 'earning', 'bonus' => 'earning', 'other' => 'earning'];

    public function create(User $actor, array $d): SettlementRun
    {
        abort_unless($actor->allows('payroll.settlement.manage'), 403);
        $hash = RequestFingerprint::make($d);

        return DB::transaction(function () use ($actor, $d, $hash) {
            $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.settlement.manage')->where('uuid', $d['employee_uuid'])->lockForUpdate()->firstOrFail();
            if ($e = SettlementRun::where('idempotency_key', $d['idempotency_key'])->first()) {
                abort_unless($e->request_hash === $hash, 409, 'Request key already belongs to another operation.');

                return $e;
            }
            if (SettlementRun::where('employee_id', $employee->id)->whereNotIn('status', ['cancelled'])->exists()) {
                throw ValidationException::withMessages(['employee_uuid' => 'A settlement already exists for this employee.']);
            }
            $inputs = array_intersect_key($d, array_flip(['group_uuid', 'daily_rate', 'salary_days', 'notice', 'include_leave_encashment', 'recover_balances', 'items', 'leave_window_days']));
            $s = SettlementRun::create(['employee_id' => $employee->id, 'last_working_date' => $d['last_working_date'], 'status' => 'draft', 'currency' => $d['currency'], 'net_payable' => '0',
                'created_by' => $actor->id, 'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash, 'inputs' => $inputs]);
            $this->calculate($actor, $s);
            Audit::record('settlement.created', 'payroll', $s->uuid, ['employee_uuid' => $employee->uuid]);

            return $s->refresh();
        });
    }

    private function dailyRate(SettlementRun $s, array $in): ?string
    {
        if (! empty($in['daily_rate'])) {
            return $in['daily_rate'];
        }
        if (app()->bound(CompensationResolver::class)) {
            try {
                return app(CompensationResolver::class)->current($s->employee_id, substr((string) $s->last_working_date, 0, 10))['daily_rate'] ?? null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** Rebuilds the itemised settlement (allowed until approval). Pure of side effects except the item rows and totals. */
    public function calculate(User $actor, SettlementRun $s): SettlementRun
    {
        abort_unless(in_array($s->status, ['draft', 'calculated', 'review'], true), 409, 'An approved settlement cannot be recalculated.');
        $in = $s->inputs ?? [];
        $cur = $s->currency;
        $exp = Money::exponent($cur);
        $items = [];
        $warnings = [];
        $rate = $this->dailyRate($s, $in);
        $money = fn (string $v) => Money::of($v, $cur)->round()->toString($exp);

        if (! empty($in['salary_days'])) {
            if ($rate === null) {
                $warnings[] = 'A daily rate is required to price salary days.';
            } else {
                $items[] = ['kind' => 'salary', 'direction' => 'pay', 'amount' => $money((string) Decimal::mul($in['salary_days'], $rate)), 'explanation' => $in['salary_days'].' day(s) of salary through the last working date at the daily rate.', 'source_ref' => 'rate:daily'];
            }
        }
        if (($in['include_leave_encashment'] ?? true) && class_exists(PayrollContract::class)) {
            try {
                $span = (int) ($in['leave_window_days'] ?? 400);
                $lwd = substr((string) $s->last_working_date, 0, 10);
                $preview = app(PayrollContract::class)->preview(date('Y-m-d', strtotime($lwd.' -'.$span.' days')), date('Y-m-d', strtotime($lwd.' +60 days')), [$s->employee_id]);
                foreach ($preview['lines'] as $l) {
                    if ($l['pay_class'] !== 'encashment') {
                        continue;
                    }
                    $ref = $l['meta']['settlement_reference'] ?? null;
                    if ($rate === null) {
                        $warnings[] = 'Leave encashment of '.$l['units'].' unit(s) needs a daily rate.';
                        continue;
                    }
                    $items[] = ['kind' => 'leave_encashment', 'direction' => 'pay', 'amount' => $money((string) Decimal::mul($l['units'], $rate)), 'explanation' => $l['units'].' encashable '.$l['leave_type_code'].' unit(s) from the leave ledger at the daily rate.', 'source_ref' => 'leave:'.$ref];
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Leave encashment references could not be read.';
            }
        }
        if (! empty($in['notice'])) {
            $short = max(0, (int) ($in['notice']['required_days'] ?? 0) - (int) ($in['notice']['served_days'] ?? 0));
            if ($short > 0) {
                if ($rate === null) {
                    $warnings[] = 'A daily rate is required to price the notice shortfall.';
                } else {
                    $items[] = ['kind' => 'notice_pay', 'direction' => 'recover', 'amount' => $money((string) Decimal::mul($short, $rate)), 'explanation' => "Notice shortfall of $short day(s) recovered at the daily rate.", 'source_ref' => 'notice'];
                }
            }
        }
        if ($in['recover_balances'] ?? true) {
            foreach (app(LoanService::class)->openBalances($s->employee_id) as $b) {
                $items[] = ['kind' => $b['type'], 'direction' => 'recover', 'amount' => $money($b['outstanding']), 'explanation' => 'Outstanding '.$b['type'].' balance recovered in full.', 'source_ref' => $b['type'].':'.$b['uuid']];
            }
        }
        foreach ($in['items'] ?? [] as $m) {
            $items[] = ['kind' => $m['kind'], 'direction' => $m['direction'], 'amount' => $money($m['amount']), 'explanation' => $m['explanation'] ?? 'Manual item', 'source_ref' => 'manual'];
        }

        $pay = Money::zero($cur);
        $rec = Money::zero($cur);
        foreach ($items as $i) {
            $i['direction'] === 'pay' ? $pay = $pay->plus(Money::of($i['amount'], $cur)) : $rec = $rec->plus(Money::of($i['amount'], $cur));
        }
        $net = $pay->minus($rec);
        DB::transaction(function () use ($s, $items, $net, $pay, $rec, $warnings, $exp) {
            SettlementItem::where('settlement_id', $s->id)->get()->each->delete();
            foreach ($items as $i) {
                SettlementItem::create($i + ['settlement_id' => $s->id]);
            }
            $s->update(['status' => 'calculated', 'net_payable' => $net->toString($exp), 'calculation_version' => $s->calculation_version + 1,
                'items_summary' => ['pay' => $pay->toString($exp), 'recover' => $rec->toString($exp), 'warnings' => $warnings, 'negative' => $net->isNegative()]]);
        });
        app(DomainEvents::class)->record(PayrollEvents::SETTLEMENT_CALCULATED, ['employee' => $s->employee_id, 'summary' => 'Settlement calculated', 'payload' => ['settlement_uuid' => $s->uuid]]);

        return $s->refresh();
    }

    public function submit(User $actor, SettlementRun $s): SettlementRun
    {
        abort_unless($actor->allows('payroll.settlement.manage'), 403);
        abort_unless($s->status === 'calculated', 409, 'Calculate the settlement before sending it for approval.');
        if ($s->items_summary['warnings'] ?? []) {
            throw ValidationException::withMessages(['settlement' => $s->items_summary['warnings']]);
        }
        $s->update(['status' => 'review']);
        Own::requestApproval($actor, 'payroll.settlement.approve', Employee::find($s->employee_id), 'Approve full and final settlement', 'settlement:'.$s->uuid, '/payroll/settlements');

        return $s;
    }

    public function decide(User $actor, SettlementRun $probe, string $decision, ?string $note = null): SettlementRun
    {
        abort_unless($actor->allows('payroll.settlement.approve'), 403);
        abort_unless(app(DataScope::class)->canAccess($actor, $probe->employee_id, 'payroll.settlement.approve'), 404);

        return DB::transaction(function () use ($actor, $probe, $decision, $note) {
            $s = SettlementRun::whereKey($probe->id)->lockForUpdate()->firstOrFail();
            abort_unless($s->status === 'review', 409, 'This settlement is not waiting for approval.');
            abort_if($s->created_by === $actor->id, 403, 'A different authorized person must approve this settlement.');
            if ($decision === 'rejected') {
                $s->update(['status' => 'calculated', 'decision_note' => $note]);
                app(TaskService::class)->completeBySource('payroll', 'settlement:'.$s->uuid);

                return $s;
            }
            if ($s->items_summary['negative'] ?? false) {
                throw ValidationException::withMessages(['settlement' => 'Recoveries exceed the amount payable. Adjust the items before approval.']);
            }
            $this->store($actor, $s);
            $s->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note]);
            app(TaskService::class)->completeBySource('payroll', 'settlement:'.$s->uuid);
            Audit::record('settlement.approved', 'payroll', $s->uuid);

            return $s;
        });
    }

    /** Writes the settlement into its own (settlement-type) run through the ResultStore, then locks the run so payslip/payment/journal flows apply. */
    private function store(User $actor, SettlementRun $s): void
    {
        $in = $s->inputs ?? [];
        $emp = Employee::findOrFail($s->employee_id);
        $groupId = isset($in['group_uuid']) ? PayrollGroup::where('uuid', $in['group_uuid'])->value('id') : null;
        $groupId ??= DB::table('employee_compensations')->where('tenant_id', Own::tid())->where('employee_id', $emp->id)->where('status', 'approved')->orderByDesc('effective_from')->value('payroll_group_id');
        if (! $groupId) {
            throw ValidationException::withMessages(['group_uuid' => 'Choose the payroll group this settlement is paid through.']);
        }
        $period = DB::table('payroll_periods')->where('tenant_id', Own::tid())->where('payroll_group_id', $groupId)->where('period_start', '<=', $s->last_working_date)->orderByDesc('period_start')->first();
        if (! $period) {
            throw ValidationException::withMessages(['group_uuid' => 'The payroll group has no period covering the last working date.']);
        }
        $number = (int) PayrollRun::where('period_id', $period->id)->max('run_number') + 1;
        $run = PayrollRun::create(['payroll_group_id' => $groupId, 'period_id' => $period->id, 'run_number' => $number, 'run_type' => 'settlement', 'off_cycle_reason' => 'settlement', 'status' => 'review', 'currency' => $s->currency, 'created_by' => $actor->id]);
        $re = PayrollRunEmployee::create(['run_id' => $run->id, 'employee_id' => $emp->id, 'payroll_group_id' => $groupId]);
        $components = [];
        $seq = 0;
        foreach (SettlementItem::where('settlement_id', $s->id)->orderBy('id')->get() as $i) {
            $kind = $i->direction === 'pay' ? (self::PAY_COMPONENT_KIND[$i->kind] ?? 'earning') : 'deduction';
            $components[] = ['component_code' => 'FNF_'.strtoupper($i->kind), 'name' => ucfirst(str_replace('_', ' ', $i->kind)).($i->direction === 'recover' ? ' recovery' : ''), 'kind' => $kind, 'amount' => Decimal::str($i->amount, 4),
                'origin' => 'settlement', 'source_ref' => $i->source_ref, 'sequence' => ++$seq, 'trace' => ['explanation' => $i->explanation]];
        }
        $result = app(ResultStore::class)->record(new ResultDraft($re->id, $s->currency, $components, PayrollSettings::ENGINE_VERSION, inputSnapshot: ['settlement' => $s->uuid, 'items' => count($components)], trace: ['kind' => 'full_and_final']));
        foreach (SettlementItem::where('settlement_id', $s->id)->get() as $i) {
            if (in_array($i->kind, ['loan', 'advance'], true) && str_starts_with((string) $i->source_ref, $i->kind.':')) {
                $uuid = substr($i->source_ref, strlen($i->kind) + 1);
                $model = $i->kind === 'loan' ? \App\Domain\Payroll\Models\EmployeeLoan::where('uuid', $uuid)->first() : \App\Domain\Payroll\Models\EmployeeAdvance::where('uuid', $uuid)->first();
                if ($model) {
                    app(LoanService::class)->recoverInSettlement($i->kind, $model->id, $run->id, $actor->id);
                }
            }
        }
        $run->update(['status' => 'locked', 'locked_by' => $actor->id, 'locked_at' => now(), 'approved_by' => $actor->id, 'approved_at' => now(), 'employees_total' => 1]);
        $s->update(['payroll_run_id' => $run->id, 'result_id' => $result->id]);
    }

    public function present(SettlementRun $s): array
    {
        $s->loadMissing('employee.person', 'items');

        return ['uuid' => $s->uuid, 'employee_uuid' => $s->employee->uuid, 'employee_name' => $s->employee->person->display_name, 'status' => $s->status, 'last_working_date' => substr((string) $s->last_working_date, 0, 10),
            'currency' => $s->currency, 'net_payable' => Decimal::str($s->net_payable, 4), 'summary' => $s->items_summary, 'calculation_version' => $s->calculation_version, 'run_uuid' => $s->payroll_run_id ? PayrollRun::find($s->payroll_run_id)?->uuid : null,
            'can_review' => $s->status === 'review' && auth()->id() !== $s->created_by,
            'items' => $s->items->map(fn ($i) => ['kind' => $i->kind, 'direction' => $i->direction, 'amount' => Decimal::str($i->amount, 4), 'explanation' => $i->explanation, 'source_ref' => $i->source_ref])->all()];
    }
}
