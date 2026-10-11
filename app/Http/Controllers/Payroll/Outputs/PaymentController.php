<?php

namespace App\Http\Controllers\Payroll\Outputs;

use App\Domain\Payroll\Models\BankFileExport;
use App\Domain\Payroll\Models\PaymentBatch;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Payments\PaymentService;
use App\Domain\Payroll\Support\PaymentFormatRegistry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PaymentController
{
    public function formats()
    {
        $reg = app(PaymentFormatRegistry::class);

        return ['data' => array_map(fn ($k) => ['code' => $k, 'label' => $reg->format($k)->label(), 'countries' => $reg->format($k)->countries()], $reg->keys())];
    }

    public function index(Request $r, PaymentService $svc)
    {
        return PaymentBatch::latest('id')->paginate(min(100, (int) $r->query('per_page', 25)))->through(fn ($b) => $svc->present($b));
    }

    public function show(string $uuid, PaymentService $svc)
    {
        return $svc->present(PaymentBatch::where('uuid', $uuid)->firstOrFail(), true);
    }

    public function prepare(Request $r, string $uuid, PaymentService $svc)
    {
        $d = $r->validate(['format' => 'nullable|string|max:40', 'payment_date' => 'nullable|date_format:Y-m-d', 'idempotency_key' => 'required|uuid']);

        return response()->json($svc->present($svc->prepare($r->user(), PayrollRun::where('uuid', $uuid)->firstOrFail(), $d), true), 201);
    }

    public function approve(Request $r, string $uuid, PaymentService $svc)
    {
        $d = $r->validate(['decision' => ['nullable', Rule::in(['approved', 'rejected'])], 'comment' => 'nullable|string|max:300']);

        return $svc->present($svc->approve($r->user(), PaymentBatch::where('uuid', $uuid)->firstOrFail(), $d['decision'] ?? 'approved', $d['comment'] ?? null));
    }

    public function generateFile(Request $r, string $uuid, PaymentService $svc)
    {
        $e = $svc->generateFile($r->user(), PaymentBatch::where('uuid', $uuid)->firstOrFail());

        return response()->json(['uuid' => $e->uuid, 'file_version' => $e->file_version, 'employee_count' => $e->employee_count, 'expires_at' => $e->expires_at?->toIso8601String(), 'download' => '/api/v1/payroll/bank-files/'.$e->uuid.'/download'], 201);
    }

    public function download(Request $r, string $uuid, PaymentService $svc)
    {
        [$path, $name] = $svc->download($r->user(), BankFileExport::where('uuid', $uuid)->firstOrFail());

        return response()->download($path, $name, ['Cache-Control' => 'no-store']);
    }

    public function submit(Request $r, string $uuid, PaymentService $svc)
    {
        return $svc->present($svc->submit($r->user(), PaymentBatch::where('uuid', $uuid)->firstOrFail()));
    }

    public function confirm(Request $r, string $uuid, PaymentService $svc)
    {
        $d = $r->validate(['preview' => 'nullable|boolean', 'updates' => 'required|array|min:1|max:1000', 'updates.*.uuid' => 'nullable|uuid', 'updates.*.reference' => 'nullable|string|max:120',
            'updates.*.status' => ['required', Rule::in(['processing', 'paid', 'failed'])], 'updates.*.bank_reference' => 'nullable|string|max:120', 'updates.*.reason' => 'nullable|string|max:300']);
        $b = PaymentBatch::where('uuid', $uuid)->firstOrFail();
        $rows = $svc->confirm($r->user(), $b, $d['updates'], 'manual', (bool) ($d['preview'] ?? false));

        return ['results' => $rows, 'batch' => $svc->present($b->refresh())];
    }

    /** Basic bank-response import: CSV with header reference,status,bank_reference,reason (status paid|failed|processing; success/rejected aliases). */
    public function importResponse(Request $r, string $uuid, PaymentService $svc)
    {
        $r->validate(['file' => 'required|file|mimes:csv,txt|max:2048', 'preview' => 'nullable|boolean']);
        $b = PaymentBatch::where('uuid', $uuid)->firstOrFail();
        $h = fopen($r->file('file')->getRealPath(), 'r');
        $head = array_map(fn ($v) => strtolower(trim((string) $v)), (array) fgetcsv($h, 0, ',', '"', '\\'));
        $alias = ['success' => 'paid', 'paid' => 'paid', 'failed' => 'failed', 'rejected' => 'failed', 'returned' => 'failed', 'processing' => 'processing', 'pending' => 'processing'];
        $updates = [];
        while (($row = fgetcsv($h, 0, ',', '"', '\\')) !== false && count($updates) < 1000) {
            $x = array_combine($head, array_pad($row, count($head), null)) ?: [];
            $updates[] = ['reference' => $x['reference'] ?? '', 'status' => $alias[strtolower(trim((string) ($x['status'] ?? '')))] ?? 'invalid', 'bank_reference' => $x['bank_reference'] ?? null, 'reason' => $x['reason'] ?? null];
        }
        fclose($h);
        $updates = array_filter($updates, fn ($u) => $u['status'] !== 'invalid');

        return ['results' => $svc->confirm($r->user(), $b, array_values($updates), 'import', (bool) $r->boolean('preview')), 'batch' => $svc->present($b->refresh())];
    }

    public function retry(Request $r, string $uuid, PaymentService $svc)
    {
        $d = $r->validate(['idempotency_key' => 'required|uuid']);

        return response()->json($svc->present($svc->retryFailed($r->user(), PaymentBatch::where('uuid', $uuid)->firstOrFail(), $d['idempotency_key']), true), 201);
    }

    public function verifyBank(Request $r, PaymentService $svc)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'note' => 'nullable|string|max:300']);

        return $svc->verifyBank($r->user(), $d['employee_uuid'], $d['note'] ?? null);
    }
}
