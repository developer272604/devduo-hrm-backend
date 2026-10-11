<?php

namespace App\Domain\Payroll\Compensation;

use App\Domain\Access\DataScope;
use App\Domain\Payroll\Models\PayrollImport;
use App\Domain\Payroll\Models\PayrollImportRow;
use App\Domain\Payroll\Models\SalaryComponent;
use App\Domain\People\Models\Employee;
use App\Models\User;
use App\Support\Spreadsheet\TabularReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Private, auditable compensation CSV/XLSX intake. File format is one component per employee per row. */
final class CompensationImportService
{
    public function __construct(private RevisionBatchService $batches) {}

    public function preview(User $actor, UploadedFile $file, array $options): array
    {
        abort_unless($actor->allows('payroll.compensation.manage'), 403);
        $reader = TabularReader::open($file->getRealPath(), $file->getClientOriginalName(), ['max_rows' => 10001, 'max_bytes' => 10485760]);
        $headers = $reader->headers();
        $rawHeaders = $reader->rawHeaders();
        $mapping = [];
        foreach (['employee_number', 'component_code', 'amount'] as $field) {
            $requested = trim((string) (array_key_exists($field, $options['mapping'] ?? []) ? ($options['mapping'][$field] ?? '') : (in_array($field, $headers, true) ? $field : '')));
            $header = $requested === '' ? '' : TabularReader::normalizeHeader($requested);
            if ($header !== '' && ! in_array($header, $headers, true)) {
                throw ValidationException::withMessages(['mapping.'.$field => 'Choose a header present in the uploaded file. Header matching ignores case and normalizes spaces.']);
            }
            $mapping[$field] = $header !== '' && in_array($header, $headers, true) ? $header : null;
        }
        if (in_array(null, $mapping, true)) {
            throw ValidationException::withMessages(['mapping' => 'Map employee number, component code, and amount to columns in the uploaded file.']);
        }
        $type = $options['type'];
        $date = $options['effective_date'];
        $reason = $options['reason'];
        $import = PayrollImport::create(['kind' => 'compensation', 'file_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''), 'status' => 'uploaded', 'mapping' => ['columns' => $mapping, 'type' => $type, 'effective_date' => $date, 'reason' => $reason], 'created_by' => $actor->id]);
        $grouped = [];
        $employeeNumbers = [];
        $count = 0;
        foreach ($reader->rows() as $number => $row) {
            $count++;
            $errors = [];
            $values = [];
            $sourceRow = [];
            foreach ($headers as $index => $header) {
                $sourceRow[$rawHeaders[$index] ?? $header] = $row[$header] ?? null;
            }
            foreach ($mapping as $field => $header) {
                $values[$field] = $row[$header] ?? '';
            }
            $employeeNumber = trim((string) ($values['employee_number'] ?? ''));
            $code = strtoupper(trim((string) ($values['component_code'] ?? '')));
            $amount = trim((string) ($values['amount'] ?? ''));
            $employee = $employeeNumber === '' ? null : app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.compensation.manage')->where('employee_number', $employeeNumber)->first();
            $component = $code === '' ? null : SalaryComponent::query()->whereRaw('UPPER(code) = ?', [$code])->where('active', true)->latest('version')->first();
            if (! $employee) {
                $errors[] = 'Employee number is missing or unavailable.';
            }
            if (! $component) {
                $errors[] = 'Component code is missing or unavailable.';
            }
            if (! preg_match('/^\d{1,12}(?:\.\d{1,6})?$/D', $amount)) {
                $errors[] = 'Amount must be a positive decimal with up to 6 decimal places.';
            } elseif ((float) $amount <= 0) {
                $errors[] = 'Amount must be greater than zero.';
            }
            $dedupe = hash('sha256', strtolower($employeeNumber).'|'.strtolower($code));
            $status = $errors ? 'error' : (isset($grouped[$employee->id]['components'][$component->id]) ? 'duplicate' : 'valid');
            if ($status === 'duplicate') {
                $errors[] = 'This employee and component occur more than once.';
            }
            if ($status === 'valid') {
                $grouped[$employee->id] ??= ['employee_uuid' => $employee->uuid, 'lines' => []];
                $employeeNumbers[$employee->uuid] = $employeeNumber;
                $grouped[$employee->id]['lines'][] = ['component_uuid' => $component->uuid, 'amount' => $amount];
                $grouped[$employee->id]['components'][$component->id] = true;
            }
            PayrollImportRow::create(['import_id' => $import->id, 'row_number' => $number, 'status' => $status, 'raw' => ['source_row' => $sourceRow, 'values' => $values], 'errors' => $errors, 'dedupe_key' => $dedupe]);
        }
        $batchEmployees = array_values(array_map(function ($row) {
            unset($row['components']);

            return $row;
        }, $grouped));
        if (count($batchEmployees) > 100) {
            throw ValidationException::withMessages(['file' => 'A compensation import may contain at most 100 unique employees.']);
        }
        $batchPreview = $batchEmployees ? $this->batches->preview($actor, ['name' => $import->file_name, 'type' => $type, 'effective_date' => $date, 'reason' => $reason, 'employees' => $batchEmployees]) : null;
        if ($batchPreview) {
            foreach ($batchPreview['rows'] as $i => $result) {
                if ($result['status'] === 'error') {
                    $employeeUuid = $result['employee_uuid'];
                    $employeeNumber = $employeeNumbers[$employeeUuid] ?? null;
                    foreach (PayrollImportRow::query()->where('import_id', $import->id)->where('status', 'valid')->get() as $candidate) {
                        if (($candidate->raw['values']['employee_number'] ?? $candidate->raw['employee_number'] ?? null) === $employeeNumber) {
                            $candidate->update(['status' => 'error', 'errors' => [$result['message']]]);
                        }
                    }
                }
            }
        }
        $rows = PayrollImportRow::query()->where('import_id', $import->id)->get();
        $import->update(['status' => 'previewed', 'rows_total' => $count, 'rows_ok' => $rows->where('status', 'valid')->count(), 'rows_error' => $rows->where('status', 'error')->count(), 'rows_duplicate' => $rows->where('status', 'duplicate')->count()]);

        return ['uuid' => $import->uuid, 'status' => $import->status, 'rows_total' => $import->rows_total, 'rows_ok' => $import->rows_ok, 'rows_error' => $import->rows_error, 'rows_duplicate' => $import->rows_duplicate,
            'mapping' => $import->mapping['columns'], 'rows' => $rows->map(fn ($r) => ['row_number' => $r->row_number, 'status' => $r->status, 'errors' => $r->errors])->all(), 'batch_preview' => $batchPreview];
    }

    public function commit(User $actor, string $uuid): array
    {
        abort_unless($actor->allows('payroll.compensation.manage'), 403);
        $import = PayrollImport::query()->where('uuid', $uuid)->where('kind', 'compensation')->firstOrFail();
        if ($import->status === 'committed') {
            return ['uuid' => $import->uuid, 'status' => 'committed', 'batch_uuid' => $import->mapping['batch_uuid'] ?? null];
        }
        abort_unless($import->status === 'previewed', 409, 'Preview this import before committing it.');
        $groups = [];
        foreach ($import->rows()->where('status', 'valid')->orderBy('row_number')->get() as $row) {
            $raw = $row->raw['values'] ?? $row->raw ?? [];
            $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.compensation.manage')->where('employee_number', $raw['employee_number'] ?? '')->first();
            $component = SalaryComponent::query()->whereRaw('UPPER(code) = ?', [strtoupper((string) ($raw['component_code'] ?? ''))])->where('active', true)->latest('version')->first();
            if (! $employee || ! $component) {
                $row->update(['status' => 'error', 'errors' => ['Employee or active component changed after preview. Preview a fresh file.']]);

                continue;
            }
            $groups[$employee->id] ??= ['employee_uuid' => $employee->uuid, 'lines' => []];
            $groups[$employee->id]['lines'][] = ['component_uuid' => $component->uuid, 'amount' => (string) $raw['amount']];
        }
        if (! $groups) {
            throw ValidationException::withMessages(['import' => 'There are no valid rows to commit.']);
        }
        $mapping = $import->mapping;
        $batch = $this->batches->submit($actor, ['name' => $import->file_name, 'type' => $mapping['type'], 'effective_date' => $mapping['effective_date'], 'reason' => $mapping['reason'], 'idempotency_key' => $import->uuid, 'employees' => array_values($groups)]);
        $submitted = $batch->revisions()->with('employee')->get()->pluck('employee.uuid')->filter()->all();
        foreach ($import->rows()->where('status', 'valid')->get() as $row) {
            $raw = $row->raw['values'] ?? $row->raw ?? [];
            $number = $raw['employee_number'] ?? '';
            $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.compensation.manage')->where('employee_number', $number)->first();
            $row->update(in_array($employee?->uuid, $submitted, true)
                ? ['status' => 'committed', 'errors' => []]
                : ['status' => 'error', 'errors' => ['This row was previewed successfully but could not be submitted. Check batch details.']]);
        }
        $import->update(['status' => 'committed', 'mapping' => $mapping + ['batch_uuid' => $batch->uuid], 'rows_ok' => $import->rows()->where('status', 'committed')->count(), 'rows_error' => $import->rows()->where('status', 'error')->count()]);

        return ['uuid' => $import->uuid, 'status' => 'committed', 'batch_uuid' => $batch->uuid, 'batch' => $this->batches->present($batch)];
    }
}
