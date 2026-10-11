<?php

namespace App\Http\Controllers\Leave\Ledger;

use App\Domain\Leave\Imports\ImportService;
use App\Domain\Leave\Models\LeaveImport;
use App\Domain\Leave\Models\LeaveImportRow;
use App\Jobs\Leave\CommitLeaveImport;
use Illuminate\Http\Request;

class ImportController extends LedgerController
{
    public function __construct(private ImportService $imports) {}

    public function index(Request $r)
    {
        $p = LeaveImport::query()->orderByDesc('id')->paginate($this->perPage($r, 20));

        return $this->page($p, collect($p->items())->map(fn ($i) => $this->imports->present($i))->all());
    }

    public function template(Request $r)
    {
        $d = $r->validate(['kind' => 'nullable|in:opening_balance,historical_requests', 'format' => 'nullable|in:csv,xlsx']);

        return $this->imports->template($d['kind'] ?? 'opening_balance', $d['format'] ?? 'csv');
    }

    public function store(Request $r)
    {
        $d = $r->validate(['file' => 'required|file', 'kind' => 'required|in:opening_balance,historical_requests']);
        $import = $this->imports->upload($r->file('file'), $d['kind'], $r->user());

        return response()->json(['data' => $this->imports->present($import)] + $this->imports->preview($import), 201);
    }

    public function show(string $uuid)
    {
        return ['data' => $this->imports->present(LeaveImport::byUuid($uuid))];
    }

    public function mapping(Request $r, string $uuid)
    {
        $d = $r->validate(['mapping' => 'required|array', 'options' => 'nullable|array', 'validate' => 'nullable|boolean']);
        $import = $this->imports->saveMapping(LeaveImport::byUuid($uuid), $d['mapping'], $d['options'] ?? []);
        if ($r->boolean('validate')) {
            $this->imports->validate($import);
        }

        return ['data' => $this->imports->present($import->refresh())];
    }

    public function validateImport(string $uuid)
    {
        return ['data' => $this->imports->present($this->imports->validate(LeaveImport::byUuid($uuid)))];
    }

    public function rows(Request $r, string $uuid)
    {
        $import = LeaveImport::byUuid($uuid);
        $p = LeaveImportRow::query()->where('import_id', $import->id)->when($r->query('status'), fn ($q, $s) => $q->where('status', $s))->orderBy('row_number')->paginate($this->perPage($r, 50));

        return $this->page($p, collect($p->items())->map(fn ($x) => ['row' => $x->row_number, 'status' => $x->status, 'raw' => $x->raw, 'errors' => $x->errors ?? [], 'warnings' => $x->warnings ?? []])->all());
    }

    public function commit(Request $r, string $uuid)
    {
        $import = LeaveImport::byUuid($uuid);
        abort_unless(in_array($import->status, ['validated', 'completed_with_errors', 'failed'], true), 409, 'Validate the import before importing it.');
        abort_if($import->valid_rows + $import->warning_rows === 0, 422, 'No row is ready to import. Download the error file to fix the rows.');
        $import->forceFill(['status' => 'queued'])->save();
        CommitLeaveImport::dispatch($import->tenant_id, $import->id, $r->user()->id);

        return response()->json(['data' => $this->imports->present($import->refresh()), 'message' => 'The import is running. Check its status here.'], 202);
    }

    public function retry(Request $r, string $uuid)
    {
        $import = LeaveImport::byUuid($uuid);
        abort_if(in_array($import->status, ['queued', 'importing', 'validating'], true), 409, 'This import is already running.');
        $this->imports->revalidateFailed($import);
        $import->forceFill(['status' => 'queued'])->save();
        CommitLeaveImport::dispatch($import->tenant_id, $import->id, $r->user()->id);

        return response()->json(['data' => $this->imports->present($import->refresh())], 202);
    }

    public function errors(string $uuid)
    {
        [$path, $name] = $this->imports->errorsFile(LeaveImport::byUuid($uuid));

        return response()->download($path, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store'])->deleteFileAfterSend(true);
    }
}
