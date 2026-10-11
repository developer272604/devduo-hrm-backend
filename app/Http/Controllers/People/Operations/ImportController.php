<?php

namespace App\Http\Controllers\People\Operations;

use App\Domain\People\Models\EmployeeImport;
use App\Domain\People\Models\EmployeeImportRow;
use App\Domain\People\Operations\ImportColumns;
use App\Domain\People\Operations\ImportRollback;
use App\Domain\People\Operations\ImportService;
use App\Domain\People\Operations\ImportValidator;
use App\Http\Controllers\People\PeopleController;
use App\Support\Spreadsheet\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ImportController extends PeopleController
{
    public function __construct(private ImportService $imports) {}

    private function find(string $uuid): EmployeeImport
    {
        return EmployeeImport::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function optionRules(): array
    {
        return ['options' => 'nullable|array', 'options.date_format' => 'nullable|in:'.implode(',', array_keys(ImportValidator::DATE_FORMATS)), 'options.skip_duplicates' => 'nullable|boolean',
            'options.allow_duplicates' => 'nullable|boolean', 'options.default_country' => 'nullable|string|size:2', 'options.default_timezone' => 'nullable|timezone', 'options.default_worker_type' => 'nullable|in:'.implode(',', \App\Domain\People\Services\EmployeeRules::WORKER_TYPES)];
    }

    public function template(Request $r)
    {
        $r->validate(['format' => 'nullable|in:csv,xlsx']);
        $catalogue = ImportColumns::catalogue();
        $org = [];
        foreach (ImportColumns::ORGANIZATION as $key => [$table]) {
            $org[$key] = DB::table($table)->where('tenant_id', $r->user()->tenant_id)->where('active', true)->orderBy('id')->limit(2)->pluck('code')->all();
        }
        $rows = [[], []];
        foreach ($catalogue as $c) {
            foreach ([0, 1] as $i) {
                $example = $c['example'];
                if (isset($org[$c['key']])) {
                    $example = $org[$c['key']][$i] ?? ($org[$c['key']][0] ?? '');
                }
                if ($i === 1 && in_array($c['key'], ['employee_number', 'first_name', 'last_name', 'personal_email', 'work_email', 'personal_phone', 'preferred_name'], true) && $example !== '') {
                    $example = match ($c['key']) {
                        'employee_number' => 'EMP-1002', 'first_name' => 'Ben', 'last_name' => 'Ford', 'personal_email' => 'ben@example.com', 'work_email' => 'ben.ford@company.com', 'personal_phone' => '+91 91234 56780', default => $example,
                    };
                }
                $rows[$i][] = $example;
            }
        }

        return Template::download(array_column($catalogue, 'key'), $rows, $r->query('format', 'csv'), 'employee-import-template');
    }

    public function store(Request $r)
    {
        $r->validate(['file' => 'required|file|max:'.(int) config('people_operations.import.max_kb', 10240)] + $this->optionRules(), ['file.max' => 'The file is larger than 10 MB. Split it into smaller files.']);
        $import = $this->imports->stage($r->user(), $r->file('file'), $r->input('options', []));
        $samples = EmployeeImportRow::query()->where('import_id', $import->id)->orderBy('row_number')->limit(3)->pluck('raw');

        return response()->json($this->imports->present($import, true) + [
            'columns' => collect($import->headers)->map(fn ($h) => ['header' => $h, 'suggested' => array_search($h, $import->mapping ?? [], true) ?: null, 'samples' => $samples->map(fn ($s) => $s[$h] ?? null)->all()])->all(),
            'targets' => ImportColumns::catalogue(), 'date_formats' => ImportValidator::DATE_FORMATS,
        ], 201);
    }

    public function index(Request $r)
    {
        $page = EmployeeImport::query()->orderByDesc('id')->paginate($this->perPage($r));
        $page->setCollection($page->getCollection()->map(fn ($i) => $this->imports->present($i)));

        return $page;
    }

    public function show(string $uuid)
    {
        return $this->imports->present($this->find($uuid), true);
    }

    public function mapping(Request $r, string $uuid)
    {
        $r->validate(['mapping' => 'required|array'] + $this->optionRules());

        return $this->imports->present($this->imports->setMapping($this->find($uuid), $r->input('mapping'), $r->has('options') ? $r->input('options') : null), true);
    }

    public function validateImport(string $uuid)
    {
        $import = $this->imports->validate($this->find($uuid));
        $sample = EmployeeImportRow::query()->where('import_id', $import->id)->whereIn('status', ['error', 'warning'])->orderBy('row_number')->limit(50)->get();

        return $this->imports->present($import, true) + ['problem_rows' => $sample->map(fn ($row) => $this->row($row))->all(), 'requires_duplicate_decision' => ($import->options['duplicate_rows'] ?? 0) > 0
            && empty($import->options['skip_duplicates']) && empty($import->options['allow_duplicates'])];
    }

    public function commit(Request $r, string $uuid)
    {
        $r->validate(['accept_errors' => 'sometimes|boolean']);

        return response()->json($this->imports->present($this->imports->commit($this->find($uuid), $r->user(), (bool) $r->boolean('accept_errors'))), 202);
    }

    public function rows(Request $r, string $uuid)
    {
        $r->validate(['status' => 'nullable|string']);
        $import = $this->find($uuid);
        $status = array_filter(explode(',', (string) $r->query('status')));
        $page = EmployeeImportRow::query()->where('import_id', $import->id)->when($status, fn ($q) => $q->whereIn('status', $status))->orderBy('row_number')->paginate($this->perPage($r, 50));
        $uuids = DB::table('employees')->whereIn('id', $page->getCollection()->pluck('employee_id')->filter())->pluck('uuid', 'id');
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->row($row, $uuids[$row->employee_id] ?? null)));

        return $page;
    }

    public function patchRow(Request $r, string $uuid, int $number)
    {
        $r->validate(['values' => 'required|array|min:1']);

        return $this->row($this->imports->patchRow($this->find($uuid), $number, $r->input('values')));
    }

    public function errorsCsv(string $uuid)
    {
        $import = $this->find($uuid);
        $path = $this->imports->errorsCsv($import);

        return response()->download($path, 'import-errors-'.$import->uuid.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store'])->deleteFileAfterSend(true);
    }

    public function retry(Request $r, string $uuid)
    {
        $r->validate(['file' => 'nullable|file|max:'.(int) config('people_operations.import.max_kb', 10240)]);
        $file = $r->file('file');
        $summary = $this->imports->retry($this->find($uuid), $r->user(), $file ? ['path' => $file->getRealPath(), 'name' => $file->getClientOriginalName()] : null);

        return response()->json($this->imports->present($this->find($uuid)) + ['corrections' => $summary], 202);
    }

    public function rollback(string $uuid, ImportRollback $rollback)
    {
        $import = $this->find($uuid);

        return $rollback->run($import) + ['import' => $this->imports->present($import->refresh())];
    }

    public function destroy(string $uuid)
    {
        $this->imports->discard($this->find($uuid));

        return response()->noContent();
    }

    private function row(EmployeeImportRow $row, ?string $employeeUuid = null): array
    {
        return ['row_number' => $row->row_number, 'status' => $row->status, 'raw' => $row->raw, 'summary' => $row->normalized['summary'] ?? null, 'errors' => $row->errors ?? [], 'warnings' => $row->warnings ?? [], 'employee_uuid' => $employeeUuid];
    }
}
