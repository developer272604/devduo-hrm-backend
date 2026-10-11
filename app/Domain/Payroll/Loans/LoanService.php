<?php

namespace App\Domain\Payroll\Loans;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\EmployeeLoan;
use App\Domain\Payroll\Models\LoanInstallment;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\SelfService\Own;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Domain\People\Models\Employee;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Support\RequestFingerprint;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Loans and salary advances. The schedule is generated once at request time and never edited after approval; recoveries, reversals,
 * waivers and write-offs are append-only ledger rows (payroll_loan_ledger); outstanding is DERIVED, never stored. Decimal/Money only.
 */
final class LoanService
{
    public const COMPONENT = 'LOAN_RECOVERY';

    /** @return array<int,array{principal:string,interest:string,amount:string}> */
    public function schedule(string $principal, string $currency, int $n, array $interest, string $method = 'equal', ?array $custom = null): array
    {
        $exp = Money::exponent($currency);
        $type = $interest['type'] ?? 'none';
        $rate = (string) ($interest['rate'] ?? '0');
        $rows = [];
        if ($method === 'custom') {
            if (! $custom || count($custom) !== $n || ! Decimal::eq(Decimal::sum($custom), $principal)) {
                throw ValidationException::withMessages(['custom_amounts' => 'Custom installments must match the installment count and add up to the principal.']);
            }
            foreach ($custom as $c) {
                $rows[] = ['principal' => Decimal::str($c, $exp), 'interest' => Decimal::str('0', $exp)];
            }
        } elseif ($type === 'reducing' && ! Decimal::isZero($rate)) {
            $r = Decimal::div($rate, 1200, 14);
            $pow = Decimal::of('1');
            for ($i = 0; $i < $n; $i++) {
                $pow = Decimal::mul($pow, Decimal::add(1, $r))->toScale(14, RoundingMode::HALF_UP);
            }
            $emi = Decimal::div(Decimal::mul($principal, Decimal::mul($r, $pow)), Decimal::sub($pow, 1), 14)->toScale($exp, RoundingMode::HALF_UP);
            $balance = Decimal::of($principal);
            for ($i = 1; $i <= $n; $i++) {
                $int = $balance->multipliedBy($r)->toScale($exp, RoundingMode::HALF_UP);
                $prin = $i === $n ? $balance : $emi->minus($int);
                if ($prin->isGreaterThan($balance)) {
                    $prin = $balance;
                }
                $rows[] = ['principal' => Decimal::str($prin, $exp), 'interest' => Decimal::str($int, $exp)];
                $balance = $balance->minus($prin);
            }
        } else {
            $parts = Money::of($principal, $currency)->allocate(array_fill(0, $n, '1'));
            $totalInt = $type === 'flat' ? Decimal::str(Decimal::div(Decimal::mul(Decimal::mul($principal, $rate), $n), 1200, 12), $exp) : '0';
            $ints = Money::of($totalInt, $currency)->allocate(array_fill(0, $n, '1'));
            foreach ($parts as $i => $p) {
                $rows[] = ['principal' => $p->toString($exp), 'interest' => $ints[$i]->toString($exp)];
            }
        }
        foreach ($rows as &$row) {
            $row['amount'] = Decimal::str(Decimal::add($row['principal'], $row['interest']), $exp);
        }

        return $rows;
    }

    public function createLoan(User $actor, array $d): EmployeeLoan
    {
        abort_unless($actor->allows('payroll.loans.manage'), 403);
        $hash = RequestFingerprint::make($d);

        return DB::transaction(function () use ($actor, $d, $hash) {
            $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.loans.manage')->where('uuid', $d['employee_uuid'])->lockForUpdate()->firstOrFail();
            if ($existing = EmployeeLoan::where('idempotency_key', $d['idempotency_key'])->first()) {
                abort_unless($existing->request_hash === $hash && $existing->employee_id === $employee->id, 409, 'Request key already belongs to another operation.');

                return $existing;
            }
            if (! $employee->isWorking()) {
                throw ValidationException::withMessages(['employee_uuid' => 'Loans can only be issued to working employees.']);
            }
            if ($d['recovery_start'] < $d['issue_date']) {
                throw ValidationException::withMessages(['recovery_start' => 'Recovery cannot start before the issue date.']);
            }
            $rows = $this->schedule($d['principal'], $d['currency'], (int) $d['installments'], $d['interest'] ?? ['type' => 'none'], $d['recovery_method'] ?? 'equal', $d['custom_amounts'] ?? null);
            $loan = EmployeeLoan::create(['employee_id' => $employee->id, 'status' => 'pending', 'principal' => $d['principal'], 'currency' => $d['currency'], 'issue_date' => $d['issue_date'],
                'recovery_start' => $d['recovery_start'], 'installments' => $d['installments'], 'interest' => $d['interest'] ?? ['type' => 'none', 'rate' => '0'], 'recovery_method' => $d['recovery_method'] ?? 'equal',
                'purpose' => $d['purpose'] ?? null, 'created_by' => $actor->id, 'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash]);
            foreach ($rows as $i => $row) {
                LoanInstallment::create($row + ['loan_id' => $loan->id, 'sequence' => $i + 1, 'due_date' => CarbonImmutable::parse($d['recovery_start'])->addMonthsNoOverflow($i)->toDateString(), 'status' => 'scheduled']);
            }
            Own::requestApproval($actor, 'payroll.loans.approve', $employee, 'Review loan request', 'loan:'.$loan->uuid, '/payroll/loans');
            Audit::record('loan.requested', 'payroll', $loan->uuid, ['employee_uuid' => $employee->uuid]);

            return $loan;
        });
    }

    public function decideLoan(User $actor, string $uuid, string $decision, ?string $note = null): EmployeeLoan
    {
        abort_unless($actor->allows('payroll.loans.approve'), 403);
        $probe = EmployeeLoan::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($actor, $probe->employee_id, 'payroll.loans.approve'), 404);

        return DB::transaction(function () use ($actor, $probe, $decision, $note) {
            $loan = EmployeeLoan::whereKey($probe->id)->lockForUpdate()->firstOrFail();
            abort_if($loan->created_by === $actor->id, 403, 'A different authorized person must review this loan.');
            abort_unless($loan->status === 'pending', 409, 'This loan has already been reviewed.');
            $loan->update(['status' => $decision === 'approved' ? 'active' : 'cancelled', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note]);
            app(TaskService::class)->completeBySource('payroll', 'loan:'.$loan->uuid);
            Audit::record('loan.'.$decision, 'payroll', $loan->uuid);

            return $loan;
        });
    }

    public function createAdvance(User $actor, array $d): EmployeeAdvance
    {
        abort_unless($actor->allows('payroll.loans.manage'), 403);
        $hash = RequestFingerprint::make($d);

        return DB::transaction(function () use ($actor, $d, $hash) {
            $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.loans.manage')->where('uuid', $d['employee_uuid'])->lockForUpdate()->firstOrFail();
            if ($existing = EmployeeAdvance::where('idempotency_key', $d['idempotency_key'])->first()) {
                abort_unless($existing->request_hash === $hash, 409, 'Request key already belongs to another operation.');

                return $existing;
            }
            $a = EmployeeAdvance::create(['employee_id' => $employee->id, 'status' => 'pending', 'amount' => $d['amount'], 'currency' => $d['currency'], 'issue_date' => $d['issue_date'],
                'recovery_start' => $d['recovery_start'], 'recovery_installments' => $d['recovery_installments'] ?? 1, 'reason' => $d['reason'] ?? null, 'created_by' => $actor->id,
                'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash]);
            Own::requestApproval($actor, 'payroll.loans.approve', $employee, 'Review advance request', 'advance:'.$a->uuid, '/payroll/loans');
            Audit::record('advance.requested', 'payroll', $a->uuid);

            return $a;
        });
    }

    public function decideAdvance(User $actor, string $uuid, string $decision, ?string $note = null): EmployeeAdvance
    {
        abort_unless($actor->allows('payroll.loans.approve'), 403);
        $probe = EmployeeAdvance::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($actor, $probe->employee_id, 'payroll.loans.approve'), 404);

        return DB::transaction(function () use ($actor, $probe, $decision, $note) {
            $a = EmployeeAdvance::whereKey($probe->id)->lockForUpdate()->firstOrFail();
            abort_if($a->created_by === $actor->id, 403, 'A different authorized person must review this advance.');
            abort_unless($a->status === 'pending', 409, 'This advance has already been reviewed.');
            $a->update(['status' => $decision === 'approved' ? 'recovering' : 'cancelled', 'approved_by' => $actor->id, 'approved_at' => now(), 'decision_note' => $note]);
            app(TaskService::class)->completeBySource('payroll', 'advance:'.$a->uuid);
            Audit::record('advance.'.$decision, 'payroll', $a->uuid);

            return $a;
        });
    }

    // ---- derived balances -------------------------------------------------------------------------------------
    public function outstanding(string $type, int $id): string
    {
        $total = $type === 'loan'
            ? (string) Decimal::sum(LoanInstallment::where('loan_id', $id)->get()->pluck('amount')->all())
            : Decimal::str(EmployeeAdvance::whereKey($id)->value('amount'), 4);
        $sum = fn (string $effect) => Decimal::sum(DB::table('payroll_loan_ledger')->where('tenant_id', Own::tid())->where('subject_type', $type)->where('subject_id', $id)->where('effect', $effect)
            ->pluck('amount')->map(fn ($v) => Decimal::fromDb($v, 4))->all());

        return Decimal::str(Decimal::add(Decimal::sub($total, $sum('reduce')), $sum('increase')), 4);
    }

    /** Waive or write off part of the outstanding balance (append-only ledger entry; needs the approver permission). */
    public function adjust(User $actor, string $type, string $uuid, string $entry, string $amount, string $note): void
    {
        abort_unless($actor->allows('payroll.loans.approve'), 403);
        $model = $type === 'loan' ? EmployeeLoan::where('uuid', $uuid)->firstOrFail() : EmployeeAdvance::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($actor, $model->employee_id, 'payroll.loans.approve'), 404);
        DB::transaction(function () use ($model, $type, $entry, $amount, $note, $actor) {
            if (Decimal::cmp($amount, $this->outstanding($type, $model->id)) > 0) {
                throw ValidationException::withMessages(['amount' => 'The amount is more than the outstanding balance.']);
            }
            $this->entry($type, $model->id, $entry, 'reduce', $amount, null, null, null, $note, $actor->id);
            $this->refreshStatus($type, $model);
            Audit::record('loan.'.$entry, 'payroll', $model->uuid);
        });
    }

    private function entry(string $type, int $id, string $entry, string $effect, string $amount, ?int $installment, ?int $runId, ?string $dedupe, ?string $note, ?int $userId, ?int $reverses = null): int
    {
        return DB::table('payroll_loan_ledger')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => Own::tid(), 'subject_type' => $type, 'subject_id' => $id, 'entry_type' => $entry, 'effect' => $effect,
            'amount' => $amount, 'installment_id' => $installment, 'run_id' => $runId, 'reverses_id' => $reverses, 'dedupe_key' => $dedupe, 'note' => $note, 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function refreshStatus(string $type, $model): void
    {
        $model->refresh();
        $zero = Decimal::isZero($this->outstanding($type, $model->id));
        $open = $type === 'loan' ? 'active' : 'recovering';
        if ($model->status === $open && $zero) {
            $model->update($type === 'loan' ? ['status' => 'closed', 'closed_at' => now()] : ['status' => 'closed']);
        } elseif ($model->status === 'closed' && ! $zero) {
            $model->update($type === 'loan' ? ['status' => 'active', 'closed_at' => null] : ['status' => 'recovering']);
        }
    }

    // ---- payroll recovery hooks (called by the run engine) -----------------------------------------------------
    /**
     * Recoveries due for one employee in a period (includes earlier unrecovered installments). Pure read; B turns each row into a
     * deduction input/component with `component_code` and keeps `reference` as provenance.
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueFor(int $employeeId, string $periodEnd): array
    {
        $out = [];
        foreach (EmployeeLoan::where('employee_id', $employeeId)->where('status', 'active')->get() as $loan) {
            foreach (LoanInstallment::where('loan_id', $loan->id)->where('status', 'scheduled')->where('due_date', '<=', $periodEnd)->orderBy('sequence')->get() as $i) {
                $out[] = ['type' => 'loan', 'uuid' => $loan->uuid, 'id' => $loan->id, 'installment_id' => $i->id, 'amount' => Decimal::str($i->amount, 4), 'currency' => $loan->currency, 'component_code' => self::COMPONENT, 'reference' => 'loan:'.$loan->uuid.':'.$i->sequence];
            }
        }
        foreach (EmployeeAdvance::where('employee_id', $employeeId)->where('status', 'recovering')->where('recovery_start', '<=', $periodEnd)->get() as $a) {
            $outstanding = $this->outstanding('advance', $a->id);
            if (Decimal::isZero($outstanding)) {
                continue;
            }
            $slice = Money::of(Decimal::str($a->amount, 4), $a->currency)->dividedBy($a->recovery_installments)->round()->toString(4);
            $out[] = ['type' => 'advance', 'uuid' => $a->uuid, 'id' => $a->id, 'installment_id' => null, 'amount' => Decimal::str(Decimal::min($slice, $outstanding), 4), 'currency' => $a->currency, 'component_code' => self::COMPONENT, 'reference' => 'advance:'.$a->uuid];
        }

        return $out;
    }

    /** Records recoveries for every employee of a LOCKED run (idempotent per run + installment). B calls it after locking. */
    public function consume(PayrollRun $run): int
    {
        $end = substr((string) DB::table('payroll_periods')->where('id', $run->period_id)->value('period_end'), 0, 10);
        $count = 0;
        DB::transaction(function () use ($run, $end, &$count) {
            foreach ($run->employees()->whereNotIn('status', ['excluded', 'held', 'failed'])->get() as $re) {
                foreach ($this->dueFor($re->employee_id, $end) as $d) {
                    $key = 'rec:'.$run->id.':'.($d['installment_id'] ?? $d['type'].$d['id']);
                    // idempotent per run + installment, but a recovery that was reversed (run reopened) may be recorded again under a fresh key
                    $prior = DB::table('payroll_loan_ledger')->where('tenant_id', Own::tid())->where('entry_type', 'recovery')->where(fn ($q) => $q->where('dedupe_key', $key)->orWhere('dedupe_key', 'like', $key.'#%'))->pluck('id');
                    if ($prior->isNotEmpty() && $prior->diff(DB::table('payroll_loan_ledger')->where('tenant_id', Own::tid())->whereIn('reverses_id', $prior)->pluck('reverses_id'))->isNotEmpty()) {
                        continue;
                    }
                    if ($prior->isNotEmpty()) {
                        $key .= '#'.$prior->count();
                    }
                    $this->entry($d['type'], $d['id'], 'recovery', 'reduce', $d['amount'], $d['installment_id'], $run->id, $key, null, null);
                    if ($d['installment_id']) {
                        LoanInstallment::whereKey($d['installment_id'])->update(['status' => 'recovered', 'recovered_run_id' => $run->id]);
                    }
                    $model = $d['type'] === 'loan' ? EmployeeLoan::find($d['id']) : EmployeeAdvance::find($d['id']);
                    if ($d['type'] === 'advance') {
                        $model->update(['recovered' => Decimal::str(Decimal::add((string) $model->recovered, $d['amount']), 4)]);
                    }
                    $this->refreshStatus($d['type'], $model);
                    $count++;
                }
            }
        });

        return $count;
    }

    /** Outstanding balances of an employee (active loans, recovering advances): [{type,id,uuid,outstanding,currency}]. */
    public function openBalances(int $employeeId): array
    {
        $out = [];
        foreach (EmployeeLoan::where('employee_id', $employeeId)->where('status', 'active')->get() as $l) {
            $out[] = ['type' => 'loan', 'id' => $l->id, 'uuid' => $l->uuid, 'outstanding' => $this->outstanding('loan', $l->id), 'currency' => $l->currency];
        }
        foreach (EmployeeAdvance::where('employee_id', $employeeId)->where('status', 'recovering')->get() as $a) {
            $out[] = ['type' => 'advance', 'id' => $a->id, 'uuid' => $a->uuid, 'outstanding' => $this->outstanding('advance', $a->id), 'currency' => $a->currency];
        }

        return array_values(array_filter($out, fn ($b) => ! Decimal::isZero($b['outstanding'])));
    }

    /** Full recovery of a balance inside a settlement run (idempotent). Remaining scheduled installments are skipped. */
    public function recoverInSettlement(string $type, int $id, int $runId, ?int $userId): void
    {
        $key = 'fnf:'.$runId.':'.$type.$id;
        if (DB::table('payroll_loan_ledger')->where('tenant_id', Own::tid())->where('dedupe_key', $key)->exists()) {
            return;
        }
        $amount = $this->outstanding($type, $id);
        if (Decimal::isZero($amount)) {
            return;
        }
        $this->entry($type, $id, 'recovery', 'reduce', $amount, null, $runId, $key, 'Recovered in full and final settlement', $userId);
        if ($type === 'loan') {
            LoanInstallment::where('loan_id', $id)->where('status', 'scheduled')->update(['status' => 'skipped']);
            $this->refreshStatus('loan', EmployeeLoan::find($id));
        } else {
            $a = EmployeeAdvance::find($id);
            $a->update(['recovered' => Decimal::str(Decimal::add((string) $a->recovered, $amount), 4)]);
            $this->refreshStatus('advance', $a);
        }
    }

    /** Reverses every recovery of a run (reopen / cancel) with compensating ledger rows; history is kept. */
    public function release(PayrollRun $run): int
    {
        $count = 0;
        DB::transaction(function () use ($run, &$count) {
            foreach (DB::table('payroll_loan_ledger')->where('tenant_id', Own::tid())->where('run_id', $run->id)->where('entry_type', 'recovery')->get() as $r) {
                $key = 'rev:'.$r->id;
                if (DB::table('payroll_loan_ledger')->where('tenant_id', Own::tid())->where('dedupe_key', $key)->exists()) {
                    continue;
                }
                $amount = Decimal::str(Decimal::fromDb($r->amount, 4), 4);
                $this->entry($r->subject_type, $r->subject_id, 'reversal', 'increase', $amount, $r->installment_id, $run->id, $key, 'Run reopened', null, $r->id);
                if ($r->installment_id) {
                    LoanInstallment::whereKey($r->installment_id)->update(['status' => 'scheduled', 'recovered_run_id' => null]);
                }
                $model = $r->subject_type === 'loan' ? EmployeeLoan::find($r->subject_id) : EmployeeAdvance::find($r->subject_id);
                if ($r->subject_type === 'advance') {
                    $model->update(['recovered' => Decimal::str(Decimal::sub((string) $model->recovered, $amount), 4)]);
                }
                $this->refreshStatus($r->subject_type, $model);
                $count++;
            }
        });

        return $count;
    }

    public function present(EmployeeLoan $l, bool $schedule = true): array
    {
        $l->loadMissing('employee.person');
        $out = ['uuid' => $l->uuid, 'employee_uuid' => $l->employee->uuid, 'employee_name' => $l->employee->person->display_name, 'status' => $l->status, 'principal' => Decimal::str($l->principal, 4), 'currency' => $l->currency,
            'issue_date' => substr((string) $l->issue_date, 0, 10), 'recovery_start' => substr((string) $l->recovery_start, 0, 10), 'installments' => $l->installments, 'interest' => $l->interest, 'purpose' => $l->purpose,
            'outstanding' => $this->outstanding('loan', $l->id), 'can_review' => $l->status === 'pending' && auth()->id() !== $l->created_by];
        if ($schedule) {
            $out['schedule'] = $l->installments()->orderBy('sequence')->get()->map(fn ($i) => ['sequence' => $i->sequence, 'due_date' => substr((string) $i->due_date, 0, 10), 'principal' => Decimal::str($i->principal, 4),'interest' => Decimal::str($i->interest, 4), 'amount' => Decimal::str($i->amount, 4), 'status' => $i->status])->all();
        }

        return $out;
    }

    public function presentAdvance(EmployeeAdvance $a): array
    {
        $a->loadMissing('employee.person');

        return ['uuid' => $a->uuid, 'employee_uuid' => $a->employee->uuid, 'employee_name' => $a->employee->person->display_name, 'status' => $a->status, 'amount' => Decimal::str($a->amount, 4), 'currency' => $a->currency,
            'issue_date' => substr((string) $a->issue_date, 0, 10), 'recovery_start' => substr((string) $a->recovery_start, 0, 10), 'recovery_installments' => $a->recovery_installments, 'reason' => $a->reason,
            'outstanding' => $this->outstanding('advance', $a->id), 'can_review' => $a->status === 'pending' && auth()->id() !== $a->created_by];
    }
}
