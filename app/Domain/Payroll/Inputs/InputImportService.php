<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Models\PayrollImport;
use App\Domain\Payroll\Models\PayrollImportRow;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\People\Models\Employee;
use App\Models\User;
use App\Support\Spreadsheet\TabularReader;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** Auditable, bounded payroll-input CSV/XLSX import with explicit commit and existing input approval gates. */
final class InputImportService
{
    public function __construct(private InputService $inputs) {}

    public function preview(User $actor, UploadedFile $file, array $options): array
    {
        abort_unless($actor->allows('payroll.inputs.manage'), 403);
        $period = PayrollPeriod::query()->with('group')->where('uuid', $options['period_uuid'])->firstOrFail();
        $reader = TabularReader::open($file->getRealPath(), $file->getClientOriginalName(), ['max_rows' => 10001, 'max_bytes' => 10485760]);
        $headers = $reader->headers();
        $rawHeaders = $reader->rawHeaders();
        $mapping = [];
        foreach (['employee_number', 'kind', 'component_code', 'amount', 'quantity'] as $field) {
            $requestedHeader = trim((string) (array_key_exists($field, $options['mapping'] ?? []) ? ($options['mapping'][$field] ?? '') : (in_array($field, $headers, true) ? $field : '')));
            $header = $requestedHeader === '' ? '' : TabularReader::normalizeHeader($requestedHeader);
            if ($header !== '' && ! in_array($header, $headers, true)) {
                throw ValidationException::withMessages(['mapping.'.$field => 'Choose a header present in the uploaded file. Header matching ignores case and normalizes spaces.']);
            }
            $mapping[$field] = $header !== '' && in_array($header, $headers, true) ? $header : null;
        }
        if (! $mapping['employee_number'] || ! $mapping['kind'] || (! $mapping['amount'] && ! $mapping['quantity'])) {
            throw ValidationException::withMessages(['mapping' => 'Map employee number, kind, and at least one of amount or quantity to file columns.']);
        }
        $import = PayrollImport::create(['kind' => 'payroll_input', 'file_name' => Str::limit(basename($file->getClientOriginalName()), 200, ''), 'status' => 'uploaded',
            'mapping' => ['period_uuid' => $period->uuid, 'source' => $options['source'], 'headers' => $headers, 'columns' => $mapping], 'created_by' => $actor->id]);
        $seen = [];
        $count = 0;
        foreach ($reader->rows() as $number => $row) {
            $count++;
            $errors = [];
            $values = [];
            foreach ($mapping as $field => $header) {
                $values[$field] = $header ? ($row[$header] ?? '') : '';
            }
            $sourceRow = [];
            foreach ($headers as $index => $header) {
                $sourceRow[$rawHeaders[$index] ?? $header] = $row[$header] ?? null;
            }
            $employeeNumber = trim((string) ($values['employee_number'] ?? ''));
            $employee = $employeeNumber === '' ? null : app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.inputs.manage')->where('employee_number', $employeeNumber)->first();
            $kind = trim((string) ($values['kind'] ?? ''));
            $amount = trim((string) ($values['amount'] ?? ''));
            $quantity = trim((string) ($values['quantity'] ?? ''));
            $component = strtoupper(trim((string) ($values['component_code'] ?? '')));
            if (! $employee) {
                $errors[] = 'Employee number is missing or unavailable.';
            }
            if (! preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $kind)) {
                $errors[] = 'Kind must start with a lowercase letter and contain only lowercase letters, digits, and underscores.';
            }
            if ($amount !== '' && ! preg_match('/^-?\d{1,14}(?:\.\d{1,6})?$/D', $amount)) {
                $errors[] = 'Amount must be a plain decimal number.';
            }
            if ($quantity !== '' && ! preg_match('/^-?\d{1,14}(?:\.\d{1,6})?$/D', $quantity)) {
                $errors[] = 'Quantity must be a plain decimal number.';
            }
            if ($amount === '' && $quantity === '') {
                $errors[] = 'Provide an amount or quantity.';
            }
            if ($component !== '' && ! preg_match('/^[A-Z][A-Z0-9_]{0,39}$/D', $component)) {
                $errors[] = 'Component code has an invalid format.';
            }
            $signature = hash('sha256', implode('|', [$employeeNumber, $kind, $component, $amount, $quantity]));
            $status = $errors ? 'error' : (isset($seen[$signature]) ? 'duplicate' : 'valid');
            if ($status === 'duplicate') {
                $errors[] = 'This employee input is duplicated in the file.';
            }
            if ($status === 'valid') {
                $seen[$signature] = true;
            }
            PayrollImportRow::create(['import_id' => $import->id, 'row_number' => $number, 'status' => $status, 'raw' => ['source_row' => $sourceRow, 'values' => $values], 'errors' => $errors, 'dedupe_key' => $signature]);
        }
        $rows = $import->rows()->get();
        $import->update(['status' => 'previewed', 'rows_total' => $count, 'rows_ok' => $rows->where('status', 'valid')->count(), 'rows_error' => $rows->where('status', 'error')->count(), 'rows_duplicate' => $rows->where('status', 'duplicate')->count()]);
        Audit::record('payroll.input_import.previewed', 'payroll', $import->uuid, ['rows' => $count, 'valid' => $import->rows_ok, 'errors' => $import->rows_error, 'duplicates' => $import->rows_duplicate]);

        return $this->present($import, $rows);
    }

    public function commit(User $actor, string $uuid): array
    {
        abort_unless($actor->allows('payroll.inputs.manage'), 403);
        $import = PayrollImport::query()->where('uuid', $uuid)->where('kind', 'payroll_input')->firstOrFail();
        if ($import->status === 'committed') {
            return $this->present($import, $import->rows()->get());
        }
        abort_unless($import->status === 'previewed', 409, 'Preview this import before committing it.');
        $periodUuid = $import->mapping['period_uuid'];
        $rows = $import->rows()->where('status', 'valid')->orderBy('row_number')->get();
        $period = PayrollPeriod::query()->where('uuid', $periodUuid)->firstOrFail();
        foreach ($rows as $row) {
            $raw = $row->raw['values'] ?? $row->raw ?? [];
            try {
                $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.inputs.manage')->where('employee_number', $raw['employee_number'] ?? '')->firstOrFail();
                $this->inputs->create($actor, ['employee_uuid' => $employee->uuid, 'period_uuid' => $period->uuid, 'source' => $import->mapping['source'], 'kind' => $raw['kind'],
                    'component_code' => ($raw['component_code'] ?? '') !== '' ? strtoupper($raw['component_code']) : null, 'amount' => ($raw['amount'] ?? '') !== '' ? $raw['amount'] : null,
                    'quantity' => ($raw['quantity'] ?? '') !== '' ? $raw['quantity'] : null, 'currency' => $period->group?->currency, 'source_ref' => 'import:'.$import->uuid.':row:'.$row->row_number,
                    'import_id' => $import->id, 'meta' => ['import_uuid' => $import->uuid, 'row_number' => $row->row_number]]);
                $row->update(['status' => 'committed', 'errors' => []]);
            } catch (Throwable $e) {
                $row->update(['status' => 'error', 'errors' => [Str::limit($this->errorMessage($e), 240)]]);
            }
        }
        $import->update(['status' => 'committed', 'rows_ok' => $import->rows()->where('status', 'committed')->count(), 'rows_error' => $import->rows()->where('status', 'error')->count()]);
        Audit::record('payroll.input_import.committed', 'payroll', $import->uuid, ['rows_ok' => $import->rows_ok, 'rows_error' => $import->rows_error]);

        return $this->present($import->refresh(), $import->rows()->get());
    }

    public function retry(User $actor, string $uuid): array
    {
        abort_unless($actor->allows('payroll.inputs.manage'), 403);
        $import = PayrollImport::query()->where('uuid', $uuid)->where('kind', 'payroll_input')->firstOrFail();
        abort_unless($import->status === 'committed', 409, 'Only a committed import can retry failed rows.');
        $period = PayrollPeriod::query()->where('uuid', $import->mapping['period_uuid'])->firstOrFail();
        foreach ($import->rows()->where('status', 'error')->orderBy('row_number')->get() as $row) {
            $raw = $row->raw['values'] ?? $row->raw ?? [];
            try {
                $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.inputs.manage')->where('employee_number', $raw['employee_number'] ?? '')->firstOrFail();
                $this->inputs->create($actor, ['employee_uuid' => $employee->uuid, 'period_uuid' => $period->uuid, 'source' => $import->mapping['source'], 'kind' => $raw['kind'],
                    'component_code' => ($raw['component_code'] ?? '') !== '' ? strtoupper($raw['component_code']) : null, 'amount' => ($raw['amount'] ?? '') !== '' ? $raw['amount'] : null,
                    'quantity' => ($raw['quantity'] ?? '') !== '' ? $raw['quantity'] : null, 'currency' => $period->group?->currency, 'source_ref' => 'import:'.$import->uuid.':row:'.$row->row_number,
                    'import_id' => $import->id, 'meta' => ['import_uuid' => $import->uuid, 'row_number' => $row->row_number]]);
                $row->update(['status' => 'committed', 'errors' => []]);
            } catch (Throwable $e) {
                $row->update(['errors' => [Str::limit($this->errorMessage($e), 240)]]);
            }
        }
        $import->update(['rows_ok' => $import->rows()->where('status', 'committed')->count(), 'rows_error' => $import->rows()->where('status', 'error')->count()]);
        Audit::record('payroll.input_import.retried', 'payroll', $import->uuid, ['rows_ok' => $import->rows_ok, 'rows_error' => $import->rows_error]);

        return $this->present($import->refresh(), $import->rows()->get());
    }

    public function history(User $actor): array
    {
        abort_unless($actor->allows('payroll.inputs.manage'), 403);

        return PayrollImport::query()->where('kind', 'payroll_input')->latest('id')->limit(50)->get()
            ->map(fn (PayrollImport $import) => $this->present($import, $import->rows()->get()))->all();
    }

    public function show(User $actor, string $uuid): array
    {
        abort_unless($actor->allows('payroll.inputs.manage'), 403);
        $import = PayrollImport::query()->where('kind', 'payroll_input')->where('uuid', $uuid)->firstOrFail();

        return $this->present($import, $import->rows()->get());
    }

    private function present(PayrollImport $import, $rows): array
    {
        return ['uuid' => $import->uuid, 'status' => $import->status, 'rows_total' => $import->rows_total, 'rows_ok' => $import->rows_ok, 'rows_error' => $import->rows_error, 'rows_duplicate' => $import->rows_duplicate,
            'mapping' => $import->mapping['columns'] ?? [], 'rows' => $rows->map(fn (PayrollImportRow $row) => ['row_number' => $row->row_number, 'status' => $row->status, 'errors' => $row->errors])->all()];
    }

    private function errorMessage(Throwable $error): string
    {
        if ($error instanceof ValidationException) {
            return collect($error->errors())->flatten()->first() ?? 'The row is invalid.';
        }
        if ($error instanceof HttpException) {
            return $error->getMessage() ?: 'The row could not be committed.';
        }
        if ($error instanceof ModelNotFoundException) {
            return 'Employee or payroll period is unavailable in your scope.';
        }
        report($error);

        return 'The row could not be committed due to an unexpected error.';
    }
}
