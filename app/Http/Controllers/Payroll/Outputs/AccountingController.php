<?php

namespace App\Http\Controllers\Payroll\Outputs;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Accounting\JournalService;
use App\Domain\Payroll\Models\AccountingExport;
use App\Domain\Payroll\Models\AccountingMapping;
use App\Domain\Payroll\Models\PayrollGroup;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Payments\PaymentService;
use App\Domain\Payroll\Support\AccountingMapperRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class AccountingController
{
    private function row(AccountingMapping $m): array
    {
        return ['uuid' => $m->uuid, 'component_code' => $m->component_code, 'kind' => $m->kind, 'group_uuid' => $m->payroll_group_id ? PayrollGroup::find($m->payroll_group_id)?->uuid : null, 'debit_account' => $m->debit_account,
            'credit_account' => $m->credit_account, 'dimensions' => $m->dimensions, 'allocation' => $m->allocation, 'effective_from' => substr((string) $m->effective_from, 0, 10), 'effective_to' => $m->effective_to ? substr((string) $m->effective_to, 0, 10) : null, 'active' => (bool) $m->active];
    }

    public function mappings()
    {
        return AccountingMapping::orderBy('component_code')->orderBy('kind')->paginate(50)->through(fn ($m) => $this->row($m));
    }

    public function storeMapping(Request $r)
    {
        $d = $r->validate(['component_code' => 'nullable|string|max:40|required_without:kind', 'kind' => ['nullable', 'required_without:component_code', Rule::in(['earning', 'deduction', 'tax', 'employee_contribution', 'employer_contribution', 'benefit', 'reimbursement'])],
            'group_uuid' => 'nullable|uuid', 'debit_account' => 'required|string|max:80', 'credit_account' => 'required|string|max:80', 'dimensions' => 'nullable|array', 'dimensions.*' => 'nullable|string|max:60',
            'allocation' => 'nullable|array|max:20', 'allocation.*.percent' => ['required_with:allocation', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/D'], 'allocation.*.dimensions' => 'nullable|array',
            'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from']);
        if (! empty($d['allocation']) && ! \App\Domain\Payroll\Support\Decimal::eq(\App\Domain\Payroll\Support\Decimal::sum(array_column($d['allocation'], 'percent')), 100)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['allocation' => 'Allocation percentages must add up to 100.']);
        }
        $group = isset($d['group_uuid']) ? PayrollGroup::where('uuid', $d['group_uuid'])->firstOrFail() : null;
        unset($d['group_uuid']);
        $m = AccountingMapping::create($d + ['payroll_group_id' => $group?->id, 'active' => true]);
        Audit::record('accounting_mapping.created', 'payroll', $m->uuid);

        return response()->json($this->row($m), 201);
    }

    public function deactivate(string $uuid)
    {
        $m = AccountingMapping::where('uuid', $uuid)->firstOrFail();
        $m->update(['active' => false]);
        Audit::record('accounting_mapping.deactivated', 'payroll', $m->uuid);

        return $this->row($m);
    }

    public function formats()
    {
        return ['data' => app(AccountingMapperRegistry::class)->keys()];
    }

    public function preview(Request $r, string $uuid, JournalService $svc)
    {
        $d = $r->validate(['posting_date' => 'nullable|date_format:Y-m-d', 'format' => 'nullable|string|max:40']);
        Audit::record('journal.previewed', 'payroll', $uuid);

        return $svc->preview(PayrollRun::where('uuid', $uuid)->firstOrFail(), $d['posting_date'] ?? now()->toDateString(), $d['format'] ?? 'journal_csv');
    }

    public function export(Request $r, string $uuid, JournalService $svc)
    {
        $d = $r->validate(['posting_date' => 'required|date_format:Y-m-d', 'format' => 'nullable|string|max:40']);
        $e = $svc->export($r->user(), PayrollRun::where('uuid', $uuid)->firstOrFail(), $d['posting_date'], $d['format'] ?? 'journal_csv');

        return response()->json(['uuid' => $e->uuid, 'version' => $e->version, 'status' => $e->status, 'total_debit' => $e->total_debit, 'total_credit' => $e->total_credit, 'line_count' => $e->line_count,
            'reconciliation' => $e->reconciliation, 'download' => '/api/v1/payroll/accounting/exports/'.$e->uuid.'/download'], 201);
    }

    public function exports(string $uuid)
    {
        $run = PayrollRun::where('uuid', $uuid)->firstOrFail();

        return ['data' => AccountingExport::where('run_id', $run->id)->orderByDesc('version')->get()->map(fn ($e) => ['uuid' => $e->uuid, 'format' => $e->format, 'version' => $e->version, 'status' => $e->status,
            'total_debit' => $e->total_debit, 'total_credit' => $e->total_credit, 'line_count' => $e->line_count, 'reconciliation' => $e->reconciliation])];
    }

    public function download(string $uuid)
    {
        $e = AccountingExport::where('uuid', $uuid)->firstOrFail();
        abort_unless($e->file_path, 404);
        Audit::record('accounting.downloaded', 'payroll', $e->uuid);

        return response()->download(Storage::disk(PaymentService::DISK)->path($e->file_path), basename($e->file_path), ['Cache-Control' => 'no-store']);
    }
}
