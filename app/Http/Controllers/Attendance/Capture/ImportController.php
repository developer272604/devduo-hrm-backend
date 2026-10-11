<?php

namespace App\Http\Controllers\Attendance\Capture;

use App\Domain\Attendance\Capture\Import\AttendanceImportService;
use App\Domain\Attendance\Capture\PunchRejected;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendanceImport;
use App\Domain\Attendance\Models\AttendanceImportRow;
use App\Jobs\Attendance\ProcessAttendanceImport;
use Illuminate\Http\Request;

class ImportController extends CaptureController
{
    public function __construct(private AttendanceImportService $imports) {}

    public function index(Request $r)
    {
        $page = AttendanceImport::query()->orderByDesc('id')->paginate($this->perPage($r, 20));

        return response()->json($this->page($page, collect($page->items())->map(fn ($i) => $this->imports->present($i))->all()));
    }

    public function template(Request $r)
    {
        $r->validate(['kind' => 'nullable|in:punches,device_events', 'format' => 'nullable|in:csv,xlsx']);

        return $this->imports->template($r->input('kind', 'punches'), $r->input('format', 'csv'));
    }

    public function store(Request $r)
    {
        $data = $r->validate(['file' => 'required|file', 'kind' => 'nullable|in:punches,device_events', 'device_id' => 'nullable|uuid']);
        $device = ! empty($data['device_id']) ? AttendanceDevice::byUuid($data['device_id']) : null;
        $import = $this->imports->upload($r->file('file'), $data['kind'] ?? 'punches', $device, $r->user());

        return response()->json(['data' => $this->imports->present($import)] + $this->imports->preview($import), 201);
    }

    public function show(string $uuid)
    {
        $import = AttendanceImport::byUuid($uuid);

        return response()->json(['data' => $this->imports->present($import)]);
    }

    public function mapping(Request $r, string $uuid)
    {
        $data = $r->validate(['mapping' => 'required|array', 'options' => 'nullable|array', 'validate' => 'sometimes|boolean']);
        $import = $this->imports->saveMapping(AttendanceImport::byUuid($uuid), $data['mapping'], $data['options'] ?? []);
        if ($data['validate'] ?? true) {
            $this->imports->validate($import);
        }

        return response()->json(['data' => $this->imports->present($import->refresh())]);
    }

    public function validateImport(string $uuid)
    {
        $import = $this->imports->validate(AttendanceImport::byUuid($uuid));

        return response()->json(['data' => $this->imports->present($import)]);
    }

    public function rows(Request $r, string $uuid)
    {
        $import = AttendanceImport::byUuid($uuid);
        $q = AttendanceImportRow::query()->where('import_id', $import->id)->orderBy('row_number');
        $r->filled('status') && $q->where('status', $r->input('status'));
        $page = $q->paginate($this->perPage($r, 50));

        return response()->json($this->page($page, collect($page->items())->map(fn ($x) => ['row' => $x->row_number, 'status' => $x->status, 'raw' => $x->raw, 'errors' => $x->errors ?? [], 'warnings' => $x->warnings ?? []])->all()));
    }

    public function commit(Request $r, string $uuid)
    {
        $import = AttendanceImport::byUuid($uuid);
        if (! in_array($import->status, ['validated', 'completed_with_errors', 'failed'], true)) {
            throw new PunchRejected('import_not_ready', 'Validate the import before importing it.', 409);
        }
        if ($import->valid_rows + $import->warning_rows === 0) {
            throw new PunchRejected('nothing_to_import', 'No row is ready to import. Download the error file to fix the rows.', 422);
        }
        $import->forceFill(['status' => 'queued'])->save();
        ProcessAttendanceImport::dispatch($import->tenant_id, $import->id, 'commit', $r->user()->id);

        return response()->json(['data' => $this->imports->present($import->refresh()), 'message' => 'The import is running. You can leave this page; check the status here.'], 202);
    }

    /** Re-checks failed rows (after fixing employees or mappings) and imports them. */
    public function retry(Request $r, string $uuid)
    {
        $import = AttendanceImport::byUuid($uuid);
        if (in_array($import->status, ['queued', 'importing', 'validating'], true)) {
            throw new PunchRejected('import_busy', 'This import is already running.', 409);
        }
        $this->imports->revalidateFailed($import);
        $import->forceFill(['status' => 'queued'])->save();
        ProcessAttendanceImport::dispatch($import->tenant_id, $import->id, 'commit', $r->user()->id);

        return response()->json(['data' => $this->imports->present($import->refresh())], 202);
    }

    public function errors(string $uuid)
    {
        [$path, $name] = $this->imports->errorsFile(AttendanceImport::byUuid($uuid));

        return response()->download($path, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store'])->deleteFileAfterSend(true);
    }
}
