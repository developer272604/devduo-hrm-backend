<?php

namespace App\Domain\Payroll\Accounting;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Organization\OrganizationRecord;
use App\Domain\Payroll\Contracts\Dto\AccountingContext;
use App\Domain\Payroll\Models\AccountingExport;
use App\Domain\Payroll\Models\AccountingMapping;
use App\Domain\Payroll\Models\PayrollCalculationResult;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Payments\PaymentService;
use App\Domain\Payroll\SelfService\Own;
use App\Domain\Payroll\Support\AccountingMapperRegistry;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\PayrollEvents;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Journals from the LOCKED results of a run (never from recalculated values). Preview is read-only; export persists a versioned file. */
final class JournalService
{
    private array $codes = [];

    private function code(string $type, ?int $id): ?string
    {
        if (! $id) {
            return null;
        }

        return $this->codes[$type][$id] ??= OrganizationRecord::forType($type)->newQuery()->whereKey($id)->value('code');
    }

    private function context(PayrollRun $run, string $postingDate, int $version): AccountingContext
    {
        if (! in_array($run->status, ['locked', 'outputs', 'payment', 'closed'], true)) {
            throw ValidationException::withMessages(['run' => 'Journals can only be produced from a locked payroll run.']);
        }
        $periodEnd = substr((string) DB::table('payroll_periods')->where('id', $run->period_id)->value('period_end'), 0, 10);
        $groupCode = DB::table('payroll_groups')->where('id', $run->payroll_group_id)->value('code');
        $agg = [];
        foreach (PayrollCalculationResult::where('run_id', $run->id)->where('is_current', true)->whereIn('status', ['ok', 'warning'])->with('components')->get() as $r) {
            $a = Employee::find($r->employee_id)?->assignmentOn($periodEnd);
            $dims = ['legal_entity' => $this->code('legal-entities', $a?->legal_entity_id), 'department' => $this->code('departments', $a?->department_id),
                'cost_center' => $this->code('cost_centers', $a?->cost_center_id), 'location' => $this->code('locations', $a?->location_id)];
            foreach ($r->components as $c) {
                $key = $c->component_code.'|'.$c->kind.'|'.json_encode($dims);
                $agg[$key] ??= ['component_code' => $c->component_code, 'kind' => $c->kind, 'amount' => '0', 'dimensions' => $dims, 'gl_code' => $c->gl_code, 'group_code' => $groupCode];
                $agg[$key]['amount'] = (string) Decimal::add($agg[$key]['amount'], $c->amount);
            }
        }
        $mappings = AccountingMapping::where('active', true)->where(fn ($q) => $q->whereNull('payroll_group_id')->orWhere('payroll_group_id', $run->payroll_group_id))->get()->map(fn ($m) => [
            'component_code' => $m->component_code, 'kind' => $m->kind, 'group_code' => $m->payroll_group_id ? $groupCode : null, 'debit_account' => $m->debit_account, 'credit_account' => $m->credit_account,
            'dimensions' => $m->dimensions, 'allocation' => $m->allocation, 'effective_from' => substr((string) $m->effective_from, 0, 10), 'effective_to' => $m->effective_to ? substr((string) $m->effective_to, 0, 10) : null, 'active' => true])->all();

        return new AccountingContext($run->uuid, $run->currency, $postingDate, array_values($agg), $mappings, $version);
    }

    /** @return array{lines:array,total_debit:string,total_credit:string,balanced:bool,reconciliation:array} */
    public function preview(PayrollRun $run, string $postingDate, string $format = JournalCsvMapper::CODE): array
    {
        $mapper = app(AccountingMapperRegistry::class)->resolve($format);
        $ctx = $this->context($run, $postingDate, 0);
        $lines = $mapper->map($ctx);
        $debit = (string) Decimal::sum(array_map(fn ($l) => $l->debit, $lines));
        $credit = (string) Decimal::sum(array_map(fn ($l) => $l->credit, $lines));

        return ['lines' => array_map(fn ($l) => ['account' => $l->account, 'debit' => $l->debit, 'credit' => $l->credit, 'currency' => $l->currency, 'component_code' => $l->componentCode, 'dimensions' => $l->dimensions], $lines),
            'total_debit' => Decimal::str($debit, 4), 'total_credit' => Decimal::str($credit, 4), 'balanced' => Decimal::eq($debit, $credit), 'reconciliation' => $this->reconcile($run, $debit, $credit)];
    }

    private function reconcile(PayrollRun $run, string $debit, string $credit): array
    {
        $net = (string) Decimal::sum(PayrollCalculationResult::where('run_id', $run->id)->where('is_current', true)->whereIn('status', ['ok', 'warning'])->get()->pluck('net')->all());
        $paid = (string) Decimal::sum(DB::table('payment_instructions as i')->join('payment_batches as b', 'b.id', '=', 'i.batch_id')->where('b.tenant_id', Own::tid())->where('b.run_id', $run->id)
            ->where('i.status', '!=', 'failed')->pluck('i.amount')->map(fn ($v) => Decimal::fromDb($v, 4))->all());
        $hasPayments = DB::table('payment_batches')->where('tenant_id', Own::tid())->where('run_id', $run->id)->exists();
        $tol = (string) PayrollSettings::get('accounting.reconcile_tolerance', '0.00');
        $diffJournal = Decimal::sub($debit, $credit)->abs();

        return ['net_total' => Decimal::str($net, 4), 'payments_total' => $hasPayments ? Decimal::str($paid, 4) : null, 'journal_difference' => Decimal::str($diffJournal, 4),
            'payments_difference' => $hasPayments ? Decimal::str(Decimal::sub($net, $paid)->abs(), 4) : null,
            'ok' => Decimal::cmp($diffJournal, $tol) <= 0, 'payments_match' => ! $hasPayments || Decimal::cmp(Decimal::sub($net, $paid)->abs(), $tol) <= 0];
    }

    public function export(User $actor, PayrollRun $run, string $postingDate, string $format = JournalCsvMapper::CODE): AccountingExport
    {
        abort_unless($actor->allows('payroll.accounting.export'), 403);

        return DB::transaction(function () use ($actor, $run, $postingDate, $format) {
            DB::table('payroll_runs')->where('id', $run->id)->lockForUpdate()->first();
            $mapper = app(AccountingMapperRegistry::class)->resolve($format);
            $version = (int) AccountingExport::where('run_id', $run->id)->where('format', $format)->max('version') + 1;
            $ctx = $this->context($run, $postingDate, $version);
            $lines = $mapper->map($ctx);
            $file = $mapper->export($lines, $ctx);
            $debit = (string) Decimal::sum(array_map(fn ($l) => $l->debit, $lines));
            $credit = (string) Decimal::sum(array_map(fn ($l) => $l->credit, $lines));
            if (! Decimal::eq($debit, $credit)) {
                throw ValidationException::withMessages(['journal' => 'The journal does not balance; it was not exported.']);
            }
            $path = 'journals/'.Own::tid().'/'.$file->filename;
            Storage::disk(PaymentService::DISK)->put($path, $file->contents);
            $recon = $this->reconcile($run, $debit, $credit);
            $export = AccountingExport::create(['run_id' => $run->id, 'format' => $format, 'version' => $version, 'status' => $recon['ok'] ? 'generated' : 'mismatch', 'total_debit' => Decimal::str($debit, 4), 'total_credit' => Decimal::str($credit, 4),
                'reconciliation' => $recon, 'file_path' => $path, 'generated_by' => $actor->id, 'posting_date' => $postingDate, 'line_count' => count($lines), 'checksum' => $file->checksum]);
            foreach ($lines as $l) {
                DB::table('accounting_export_lines')->insert(['tenant_id' => Own::tid(), 'export_id' => $export->id, 'account' => $l->account, 'debit' => $l->debit, 'credit' => $l->credit, 'currency' => $l->currency,
                    'dimensions' => json_encode($l->dimensions), 'component_code' => $l->componentCode, 'memo' => $l->memo, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(DomainEvents::class)->record(PayrollEvents::ACCOUNTING_EXPORTED, ['summary' => 'Payroll journal exported', 'payload' => ['run_uuid' => $run->uuid, 'version' => $version], 'subject' => ['payroll_run', $run->id]]);
            Audit::record('accounting.exported', 'payroll', $export->uuid, ['run_uuid' => $run->uuid, 'version' => $version]);

            return $export;
        });
    }
}
