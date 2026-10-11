<?php

namespace App\Domain\Payroll\Payments;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Payroll\Contracts\Dto\PaymentBatchData;
use App\Domain\Payroll\Models\BankFileExport;
use App\Domain\Payroll\Models\PaymentBatch;
use App\Domain\Payroll\Models\PaymentInstruction;
use App\Domain\Payroll\Models\PayrollApproval;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\SelfService\Own;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Domain\Payroll\Support\PaymentFormatRegistry;
use App\Domain\Payroll\Support\PayrollEvents;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\Payroll\Support\PayrollStates;
use App\Domain\People\Models\Employee;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Support\RequestFingerprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Payment batches from LOCKED runs. Generating a bank file is not payment: "paid" exists only after an explicit confirmation
 * (manual or imported bank response) that carries a reference. Release is maker-checker (prepare vs approve are different users).
 */
final class PaymentService
{
    public const DISK = 'payroll_files';

    public const TERMINAL = ['paid', 'failed'];

    private const TRANSITIONS = ['prepared' => ['submitted', 'failed'], 'submitted' => ['processing', 'paid', 'failed'], 'processing' => ['paid', 'failed'], 'paid' => [], 'failed' => []];

    private function accountFingerprint(array $details, object $account): string
    {
        return hash_hmac('sha256', json_encode([$account->bank_name, $account->account_holder, $account->country, $account->currency, $details], JSON_THROW_ON_ERROR), config('app.key'));
    }

    /** @return array{0:object,1:array} primary active account row and its decrypted details */
    private function primaryAccount(Employee $employee): ?array
    {
        $acc = DB::table('employee_bank_accounts')->where('tenant_id', Own::tid())->where('person_id', $employee->person_id)->where('is_primary', true)->where('status', 'active')->first();
        if (! $acc) {
            return null;
        }
        $details = json_decode(Crypt::decryptString($acc->details_encrypted), true) ?: [];

        return [$acc, $details];
    }

    public function verifyBank(User $actor, string $employeeUuid, ?string $note = null): array
    {
        abort_unless($actor->allows('payroll.payments.prepare'), 403);
        $employee = app(\App\Domain\Access\DataScope::class)->constrain(Employee::query(), $actor, 'payroll.payments.prepare')->where('uuid', $employeeUuid)->firstOrFail();
        $found = $this->primaryAccount($employee);
        if (! $found) {
            throw ValidationException::withMessages(['employee_uuid' => 'The employee has no primary bank account.']);
        }
        [$acc, $details] = $found;
        $fp = $this->accountFingerprint($details, $acc);
        DB::table('payroll_bank_verifications')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => Own::tid(), 'employee_id' => $employee->id, 'bank_account_id' => $acc->id, 'fingerprint' => $fp,
            'status' => 'verified', 'method' => 'manual', 'verified_by' => $actor->id, 'verified_at' => now(), 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
        Audit::record('bank.verified', 'payroll', $employee->uuid);

        return ['employee_uuid' => $employee->uuid, 'account' => '****'.$acc->account_last4, 'verified' => true];
    }

    private function isVerified(object $acc, array $details): bool
    {
        return DB::table('payroll_bank_verifications')->where('tenant_id', Own::tid())->where('bank_account_id', $acc->id)->where('fingerprint', $this->accountFingerprint($details, $acc))->where('status', 'verified')->exists();
    }

    public function prepare(User $actor, PayrollRun $run, array $d): PaymentBatch
    {
        abort_unless($actor->allows('payroll.payments.prepare'), 403);
        $format = $d['format'] ?? 'generic_csv';
        $registry = app(PaymentFormatRegistry::class);
        $allowed = PayrollSettings::get('payments.allowed_formats') ?: [];
        if (! $registry->has($format) || ($allowed && ! in_array($format, $allowed, true))) {
            throw ValidationException::withMessages(['format' => 'This payment format is not available. Available: '.implode(', ', $allowed ?: $registry->keys()).'.']);
        }
        $hash = RequestFingerprint::make($d + ['run' => $run->uuid]);

        return DB::transaction(function () use ($actor, $run, $d, $format, $hash) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($existing = PaymentBatch::where('idempotency_key', $d['idempotency_key'])->first()) {
                abort_unless($existing->request_hash === $hash, 409, 'Request key already belongs to another operation.');

                return $existing;
            }
            if (! in_array($run->status, ['locked', 'outputs', 'payment', 'closed'], true)) {
                throw ValidationException::withMessages(['run' => 'Payments can only be prepared from a locked payroll run.']);
            }
            if (PaymentBatch::where('run_id', $run->id)->where('status', '!=', 'failed')->whereNull('retry_of_id')->exists()) {
                throw ValidationException::withMessages(['run' => 'A payment batch already exists for this run.']);
            }
            [$instructions, $exceptions, $recon] = $this->build($run);
            if (! $instructions) {
                throw ValidationException::withMessages(['run' => 'No employee in this run can be paid yet. Review the exceptions.', 'exceptions' => $exceptions]);
            }
            $batch = $this->persist($actor, $run, $format, $d, $hash, $instructions, $exceptions, $recon, null);
            Own::requestApproval($actor, 'payroll.payments.approve', null, 'Approve payment batch', 'paybatch:'.$batch->uuid, '/payroll/payments');

            return $batch;
        });
    }

    /** @return array{0:array,1:array,2:array} */
    private function build(PayrollRun $run): array
    {
        $require = (bool) PayrollSettings::get('payments.require_bank_verification', true);
        $instructions = [];
        $exceptions = [];
        $netTotal = '0';
        $exceptionNet = '0';
        foreach (PayrollCalculationResult::where('run_id', $run->id)->where('is_current', true)->whereIn('status', ['ok', 'warning'])->orderBy('employee_id')->get() as $r) {
            $net = Decimal::str($r->net, 4);
            $netTotal = (string) Decimal::add($netTotal, $net);
            $employee = Employee::with('person')->find($r->employee_id);
            $reason = null;
            $found = null;
            if (Decimal::cmp($net, 0) <= 0) {
                $reason = 'Net pay is zero or negative.';
            } elseif (! ($found = $this->primaryAccount($employee))) {
                $reason = 'No primary bank account on file.';
            } elseif ($found[0]->currency !== $r->currency) {
                $reason = 'Bank account currency differs from the payroll currency.';
            } elseif ($require && ! $this->isVerified($found[0], $found[1])) {
                $reason = 'Bank details are not verified (or changed after verification).';
            }
            if ($reason) {
                $exceptions[] = ['employee_uuid' => $employee->uuid, 'employee_number' => $employee->employee_number, 'reason' => $reason];
                $exceptionNet = (string) Decimal::add($exceptionNet, $net);
                continue;
            }
            $instructions[] = ['employee' => $employee, 'result' => $r, 'amount' => $net, 'beneficiary' => ['account_holder' => $found[0]->account_holder, 'bank_name' => $found[0]->bank_name, 'country' => $found[0]->country] + $found[1]];
        }
        $instructed = (string) Decimal::sum(array_column($instructions, 'amount'));
        $recon = ['net_total' => Decimal::str($netTotal, 4), 'instructed' => Decimal::str($instructed, 4), 'excluded_net' => Decimal::str($exceptionNet, 4),
            'difference' => Decimal::str(Decimal::sub(Decimal::sub($netTotal, $instructed), $exceptionNet), 4)];

        return [$instructions, $exceptions, $recon];
    }

    private function persist(User $actor, PayrollRun $run, string $format, array $d, string $hash, array $instructions, array $exceptions, array $recon, ?PaymentBatch $retryOf): PaymentBatch
    {
        $batch = PaymentBatch::create(['run_id' => $run->id, 'format' => $format, 'currency' => $run->currency, 'status' => 'prepared', 'version' => ($retryOf?->version ?? 0) + 1, 'employee_count' => count($instructions),
            'total_amount' => (string) Decimal::sum(array_column($instructions, 'amount')), 'prepared_by' => $actor->id, 'prepared_at' => now(), 'payment_date' => $d['payment_date'] ?? now()->toDateString(), 'exceptions' => $exceptions,
            'reconciliation' => $recon, 'retry_of_id' => $retryOf?->id, 'idempotency_key' => $d['idempotency_key'], 'request_hash' => $hash]);
        foreach ($instructions as $i) {
            PaymentInstruction::create(['batch_id' => $batch->id, 'employee_id' => $i['employee']->id, 'result_id' => $i['result']->id, 'amount' => $i['amount'], 'currency' => $run->currency, 'status' => 'prepared',
                'beneficiary' => $i['beneficiary'], 'reference' => 'PAY-'.substr($run->uuid, 0, 8).'-'.$i['employee']->employee_number.'-'.$batch->version, 'attempt' => $batch->version, 'status_at' => now()]);
        }
        PayrollApproval::create(['subject_type' => 'bank_file', 'subject_id' => $batch->id, 'step' => 1, 'role' => 'approver', 'status' => 'pending', 'requested_by' => $actor->id]);
        Audit::record('payment_batch.prepared', 'payroll', $batch->uuid, ['run_uuid' => $run->uuid, 'employees' => count($instructions)]);

        return $batch;
    }

    public function approve(User $actor, PaymentBatch $batch, string $decision = 'approved', ?string $comment = null): PaymentBatch
    {
        abort_unless($actor->allows('payroll.payments.approve'), 403);

        return DB::transaction(function () use ($actor, $batch, $decision, $comment) {
            $b = PaymentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless($b->status === 'prepared' && ! $b->approved_by, 409, 'This batch is not waiting for approval.');
            if (PayrollSettings::get('approvals.bank_file_checker', true)) {
                abort_if($b->prepared_by === $actor->id, 403, 'A different authorized person must approve this payment batch.');
            }
            $approval = PayrollApproval::where('subject_type', 'bank_file')->where('subject_id', $b->id)->where('status', 'pending')->first();
            $approval?->update(['status' => $decision, 'decided_by' => $actor->id, 'decided_at' => now(), 'comment' => $comment]);
            if ($decision === 'approved') {
                $b->update(['approved_by' => $actor->id, 'approved_at' => now()]);
            } else {
                $this->setBatchStatus($b, 'failed', 'Rejected: '.($comment ?? 'no reason given'));
                $b->instructions()->update(['status' => 'failed', 'failure_reason' => 'Batch rejected']);
            }
            app(TaskService::class)->completeBySource('payroll', 'paybatch:'.$b->uuid);
            Audit::record('payment_batch.'.$decision, 'payroll', $b->uuid);

            return $b;
        });
    }

    private function setBatchStatus(PaymentBatch $b, string $status, ?string $note = null): void
    {
        $b->update(['status' => $status] + ($note ? ['failure_note' => mb_substr($note, 0, 300)] : []));
    }

    /** @return array{export:BankFileExport,problems:array} */
    public function generateFile(User $actor, PaymentBatch $batch): BankFileExport
    {
        abort_unless($actor->allows('payroll.payments.prepare'), 403);

        return DB::transaction(function () use ($actor, $batch) {
            $b = PaymentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($b->status !== 'prepared' && $b->status !== 'submitted') {
                throw ValidationException::withMessages(['batch' => 'A file can only be generated for a prepared or submitted batch.']);
            }
            if (PayrollSettings::get('approvals.bank_file_checker', true) && ! $b->approved_by) {
                throw ValidationException::withMessages(['batch' => 'The batch must be approved by a second person before the file is generated.']);
            }
            $run = PayrollRun::findOrFail($b->run_id);
            $rows = [];
            foreach ($b->instructions()->whereIn('status', ['prepared', 'submitted'])->get() as $i) {
                $emp = Employee::with('person')->find($i->employee_id);
                $rows[] = ['employee_uuid' => $emp->uuid, 'employee_number' => $emp->employee_number, 'name' => $emp->person->display_name, 'amount' => Decimal::str($i->amount, Money::exponent($b->currency)), 'reference' => $i->reference, 'beneficiary' => $i->beneficiary];
            }
            $version = (int) $b->exports()->max('file_version') + 1;
            $data = new PaymentBatchData($b->uuid, $run->uuid, $b->currency, substr((string) $b->payment_date, 0, 10), $version, $rows);
            $format = app(PaymentFormatRegistry::class)->format($b->format);
            if ($problems = $format->validate($data)) {
                throw ValidationException::withMessages(['batch' => $problems]);
            }
            $file = $format->generate($data);
            $path = 'bank-files/'.Own::tid().'/'.$b->uuid.'-v'.$version.'-'.$file->filename;
            Storage::disk(self::DISK)->put($path, $file->contents);
            $export = BankFileExport::create(['batch_id' => $b->id, 'format' => $b->format, 'file_version' => $version, 'status' => 'generated', 'employee_count' => $file->rows, 'total_amount' => $file->total,
                'file_path' => $path, 'checksum' => $file->checksum, 'generated_by' => $actor->id, 'generated_at' => now(), 'expires_at' => now()->addDays((int) PayrollSettings::get('payments.bank_file_retention_days', 7))]);
            app(DomainEvents::class)->record(PayrollEvents::PAYMENT_FILE_GENERATED, ['summary' => 'Payment file generated', 'payload' => ['batch_uuid' => $b->uuid, 'run_uuid' => $run->uuid, 'file_version' => $version], 'subject' => ['payment_batch', $b->id]]);
            Audit::record('bank_file.generated', 'payroll', $b->uuid, ['version' => $version]);

            return $export;
        });
    }

    public function download(User $actor, BankFileExport $export): array
    {
        abort_unless($actor->allows('payroll.payments.prepare'), 403);
        abort_unless($export->file_path && $export->expires_at && $export->expires_at->isFuture() && $export->status !== 'withdrawn', 410, 'This file has expired. Generate a new version.');
        $export->increment('download_count');
        $export->update(['status' => 'downloaded']);
        Audit::record('bank_file.downloaded', 'payroll', $export->uuid);

        return [Storage::disk(self::DISK)->path($export->file_path), basename($export->file_path)];
    }

    /** Marks the file as handed to the bank. It is NOT payment. */
    public function submit(User $actor, PaymentBatch $batch): PaymentBatch
    {
        abort_unless($actor->allows('payroll.payments.prepare'), 403);
        abort_unless($batch->status === 'prepared' && $batch->exports()->exists(), 409, 'Generate the bank file before marking the batch as submitted.');
        $batch->instructions()->where('status', 'prepared')->update(['status' => 'submitted', 'status_at' => now()]);
        $batch->update(['status' => 'submitted', 'submitted_at' => now()]);
        $this->advanceRun((int) $batch->run_id, 'payment');
        Audit::record('payment_batch.submitted', 'payroll', $batch->uuid);

        return $batch;
    }

    /**
     * Applies confirmations (manual or imported). updates[]: {reference|uuid, status: processing|paid|failed, bank_reference?, reason?}.
     * "paid" requires a bank reference. Idempotent: repeating the same status is a no-op; a terminal status never changes.
     *
     * @return array<int,array<string,mixed>> per-row outcome
     */
    public function confirm(User $actor, PaymentBatch $batch, array $updates, string $source = 'manual', bool $preview = false): array
    {
        abort_unless($actor->allows('payroll.payments.confirm'), 403);
        $out = [];
        DB::transaction(function () use ($actor, $batch, $updates, $source, $preview, &$out) {
            $b = PaymentBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($b->status, ['submitted', 'processing', 'partially_paid'], true), 409, 'Confirmations can only be recorded for a submitted batch.');
            foreach ($updates as $u) {
                $i = PaymentInstruction::where('batch_id', $b->id)->where(fn ($q) => isset($u['uuid']) ? $q->where('uuid', $u['uuid']) : $q->where('reference', $u['reference'] ?? ''))->first();
                $to = $u['status'] ?? '';
                if (! $i) {
                    $out[] = ['key' => $u['uuid'] ?? $u['reference'] ?? null, 'result' => 'not_found'];
                    continue;
                }
                if ($i->status === $to) {
                    $out[] = ['key' => $i->reference, 'result' => 'unchanged'];
                    continue;
                }
                if (! in_array($to, self::TRANSITIONS[$i->status] ?? [], true)) {
                    $out[] = ['key' => $i->reference, 'result' => 'rejected', 'message' => "A payment in \"{$i->status}\" cannot become \"$to\"."];
                    continue;
                }
                if ($to === 'paid' && PayrollSettings::get('payments.require_confirmation_for_paid', true) && empty($u['bank_reference'])) {
                    $out[] = ['key' => $i->reference, 'result' => 'rejected', 'message' => 'A bank reference is required to mark a payment as paid.'];
                    continue;
                }
                if (! $preview) {
                    $from = $i->status;
                    $i->update(['status' => $to, 'status_at' => now(), 'bank_reference' => $u['bank_reference'] ?? $i->bank_reference, 'failure_reason' => $to === 'failed' ? mb_substr($u['reason'] ?? 'Rejected by bank', 0, 300) : null, 'paid_at' => $to === 'paid' ? now() : null]);
                    DB::table('payment_status_events')->insert(['tenant_id' => Own::tid(), 'batch_id' => $b->id, 'instruction_id' => $i->id, 'from_status' => $from,'to_status' => $to, 'source' => $source,
                        'reference' => $u['bank_reference'] ?? null, 'reason' => $u['reason'] ?? null, 'user_id' => $actor->id, 'created_at' => now()]);
                }
                $out[] = ['key' => $i->reference, 'result' => $preview ? 'would_apply' : 'applied', 'status' => $to];
            }
            if (! $preview) {
                $this->recompute($b, $actor);
            }
        });

        return $out;
    }

    /** Moves the run along B's state machine (shortest legal path, e.g. locked -> outputs -> payment). 'closed' only when every batch of the run is paid. */
    private function advanceRun(int $runId, string $target, ?int $paidBatchId = null): void
    {
        $run = PayrollRun::find($runId);
        if (! $run || $run->status === $target) {
            return;
        }
        if ($target === 'closed' && PaymentBatch::where('run_id', $runId)->whereNull('retry_of_id')->where('status', '!=', 'paid')->exists()) {
            return;
        }
        $path = [];
        $cur = $run->status;
        while ($cur !== $target) {
            $next = $target;
            if (! in_array($target, PayrollStates::RUN_TRANSITIONS[$cur] ?? [], true)) {
                $next = in_array('outputs', PayrollStates::RUN_TRANSITIONS[$cur] ?? [], true) ? 'outputs' : null;
            }
            if ($next === null || in_array($next, $path, true)) {
                return;
            }
            $path[] = $next;
            $cur = $next;
        }
        foreach ($path as $next) {
            PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, $run->status, $next, 'status');
            $run->update(['status' => $next, 'stage' => $next === 'closed' ? 11 : ($next === 'payment' ? 10 : 9)]);
        }
        if ($run->run_type === 'regular') {
            \App\Domain\Payroll\Runs\PeriodSync::follow($run->period, $target, auth()->id());
        }
        Audit::record('payroll.run.'.$target, 'payroll', $run->uuid);
    }

    private function recompute(PaymentBatch $b, User $actor): void
    {
        $c = $b->instructions()->get()->countBy('status');
        $n = $b->instructions()->count();
        $pending = ($c['prepared'] ?? 0) + ($c['submitted'] ?? 0) + ($c['processing'] ?? 0);
        $paid = $c['paid'] ?? 0;
        $status = $paid === $n ? 'paid' : (($c['failed'] ?? 0) === $n ? 'failed' : ($pending === 0 && $paid > 0 ? 'partially_paid' : (($c['processing'] ?? 0) > 0 ? 'processing' : $b->status)));
        if ($status !== $b->status) {
            $b->update(['status' => $status] + ($status === 'paid' || $status === 'partially_paid' ? ['confirmed_at' => now(), 'confirmed_by' => $actor->id, 'confirmation_reference' => 'confirmed'] : []));
            if ($status === 'paid') {
                $this->advanceRun((int) $b->run_id, 'closed', $b->id);
                app(DomainEvents::class)->record(PayrollEvents::PAYROLL_PAID, ['summary' => 'Payroll payment confirmed', 'payload' => ['batch_uuid' => $b->uuid], 'subject' => ['payment_batch', $b->id]]);
            }
        }
    }

    /** Failed payments get a new batch version containing only the failed instructions (original rows and reasons are kept). */
    public function retryFailed(User $actor, PaymentBatch $batch, string $idempotencyKey): PaymentBatch
    {
        abort_unless($actor->allows('payroll.payments.prepare'), 403);
        abort_unless(in_array($batch->status, ['partially_paid', 'failed'], true), 409, 'Only a batch with failed payments can be retried.');

        return DB::transaction(function () use ($actor, $batch, $idempotencyKey) {
            if ($e = PaymentBatch::where('idempotency_key', $idempotencyKey)->first()) {
                return $e;
            }
            $run = PayrollRun::findOrFail($batch->run_id);
            $failed = $batch->instructions()->where('status', 'failed')->get();
            $instructions = [];
            foreach ($failed as $f) {
                $instructions[] = ['employee' => Employee::find($f->employee_id), 'result' => PayrollCalculationResult::find($f->result_id), 'amount' => Decimal::str($f->amount, 4), 'beneficiary' => $f->beneficiary];
            }
            $new = $this->persist($actor, $run, $batch->format, ['idempotency_key' => $idempotencyKey, 'payment_date' => now()->toDateString()], RequestFingerprint::make(['retry' => $batch->uuid, 'k' => $idempotencyKey]), $instructions, [], ['retry_of' => $batch->uuid], $batch);
            Own::requestApproval($actor, 'payroll.payments.approve', null, 'Approve payment retry batch', 'paybatch:'.$new->uuid, '/payroll/payments');

            return $new;
        });
    }

    public function present(PaymentBatch $b, bool $detail = false): array
    {
        $out = ['uuid' => $b->uuid, 'run_uuid' => PayrollRun::find($b->run_id)?->uuid, 'format' => $b->format, 'status' => $b->status, 'version' => $b->version, 'currency' => $b->currency, 'employee_count' => $b->employee_count,
            'total_amount' => Decimal::str($b->total_amount, 4), 'payment_date' => $b->payment_date ? substr((string) $b->payment_date, 0, 10) : null, 'prepared_at' => $b->prepared_at?->toIso8601String(), 'approved' => (bool) $b->approved_by,
            'can_approve' => $b->status === 'prepared' && ! $b->approved_by && auth()->id() !== $b->prepared_by, 'reconciliation' => $b->reconciliation, 'exceptions' => $b->exceptions, 'retry_of_id' => $b->retry_of_id ? PaymentBatch::find($b->retry_of_id)?->uuid : null,
            'files' => $b->exports()->orderBy('file_version')->get()->map(fn ($e) => ['uuid' => $e->uuid, 'file_version' => $e->file_version, 'status' => $e->status, 'employee_count' => $e->employee_count, 'expires_at' => $e->expires_at?->toIso8601String()])->all()];
        if ($detail) {
            $out['instructions'] = $b->instructions()->get()->map(function ($i) {
                $emp = Employee::with('person')->find($i->employee_id);

                return ['uuid' => $i->uuid, 'employee_uuid' => $emp->uuid, 'employee_name' => $emp->person->display_name, 'amount' => Decimal::str($i->amount, 4), 'status' => $i->status, 'reference' => $i->reference,
                    'account' => Own::mask($i->beneficiary['account_number'] ?? ($i->beneficiary['iban'] ?? null)), 'bank_reference' => $i->bank_reference, 'failure_reason' => $i->failure_reason];
            })->all();
        }

        return $out;
    }
}
