<?php

namespace App\Domain\People\Operations;

use App\Domain\Audit\Audit;
use App\Domain\People\Config\CustomFieldService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeImport;
use App\Domain\People\Models\EmployeeImportRow;
use App\Domain\People\Services\EmployeeService;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Commit phase of an import (runs in the queue inside TenantRunner). Rows are processed in dependency order
 * (managers before their reports), each row in its own transaction, so one bad row never blocks the rest and a
 * re-run skips rows that were already imported.
 */
final class ImportProcessor
{
    public function __construct(private EmployeeService $employees, private ImportService $imports) {}

    public function run(EmployeeImport $import, User $user): void
    {
        Auth::setUser($user);
        if (! $user->allows('people.import')) {
            $import->update(['status' => 'failed', 'finished_at' => now(), 'error' => 'The user who started this import no longer has permission to import.']);

            return;
        }
        $options = $import->options ?? [];
        $skip = ! empty($options['skip_duplicates']);
        $allow = ! empty($options['allow_duplicates']);

        $pending = [];
        $remaining = [];
        EmployeeImportRow::query()->where('import_id', $import->id)->whereIn('status', ['valid', 'warning'])->orderBy('id')->chunkById(500, function ($rows) use (&$pending, &$remaining) {
            foreach ($rows as $row) {
                $number = isset($row->normalized['employment']['employee_number']) ? mb_strtolower($row->normalized['employment']['employee_number']) : null;
                $manager = ! empty($row->normalized['manager']['number']) && empty($row->normalized['manager']['employee_id']) ? mb_strtolower($row->normalized['manager']['number']) : null;
                $remaining[$row->id] = ['n' => $number, 'm' => $manager];
                $number && $pending[$number] = true;
            }
        });

        $processed = 0;
        while ($remaining) {
            $wave = array_keys(array_filter($remaining, fn ($e) => $e['m'] === null || ! isset($pending[$e['m']])));
            if (! $wave) {
                EmployeeImportRow::query()->whereIn('id', array_keys($remaining))->update(['status' => 'error', 'errors' => json_encode([['field' => 'manager_employee_number', 'message' => 'The manager chain forms a circle.']])]);
                break;
            }
            foreach (array_chunk($wave, 100) as $ids) {
                foreach (EmployeeImportRow::query()->whereIn('id', $ids)->orderBy('row_number')->get() as $row) {
                    $this->row($row, $user, $skip, $allow);
                    $processed++;
                }
                $import->update(['processed_rows' => $processed]);
            }
            foreach ($wave as $id) {
                if ($remaining[$id]['n']) {
                    unset($pending[$remaining[$id]['n']]);
                }
                unset($remaining[$id]);
            }
        }

        $this->imports->recount($import);
        $import->refresh();
        $status = match (true) {
            $import->imported_rows > 0 && $import->error_rows > 0 => 'partial', $import->imported_rows === 0 && $import->error_rows > 0 => 'failed', default => 'completed',
        };
        $import->update(['status' => $status, 'finished_at' => now(), 'processed_rows' => $processed]);
        Audit::record('import_finished', 'people', 'employee-import:'.$import->uuid, ['status' => $status, 'imported' => $import->imported_rows, 'errors' => $import->error_rows, 'skipped' => $import->skipped_rows]);
    }

    private function row(EmployeeImportRow $row, User $user, bool $skip, bool $allow): void
    {
        $n = $row->normalized;
        $duplicate = collect($row->warnings ?? [])->contains('code', 'possible_duplicate');
        if ($duplicate && $skip) {
            EmployeeImportRow::query()->whereKey($row->id)->update(['status' => 'skipped']);

            return;
        }
        try {
            DB::transaction(function () use ($row, $n, $user, $duplicate, $allow) {
                $managerId = null;
                if (! empty($n['manager']['employee_id'])) {
                    $managerId = Employee::query()->whereKey($n['manager']['employee_id'])->value('id') ?? abort(422, 'The manager no longer exists.');
                } elseif (! empty($n['manager']['number'])) {
                    $number = $n['manager']['number'];
                    $managerId = Employee::query()->where('employee_number', $number)->value('id') ?? Employee::query()->whereRaw('LOWER(employee_number) = ?', [mb_strtolower($number)])->value('id')
                        ?? abort(422, "Manager '".$number."' could not be found. If the manager is in this file, that row may have failed.");
                }
                $employee = $this->employees->create(['person' => $n['person'], 'employment' => $n['employment'], 'assignment' => $n['assignment'], 'manager_id' => $managerId,
                    'confirm_duplicates' => $duplicate && $allow], ['source' => 'import']);
                $custom = app(CustomFieldService::class);
                if (! empty($n['custom_fields'])) {
                    $custom->setValues($user, $employee, $n['custom_fields']);
                }
                $custom->applyDefaults($employee);
                EmployeeImportRow::query()->whereKey($row->id)->update(['status' => 'imported', 'employee_id' => $employee->id, 'errors' => null]);
            });
        } catch (\Throwable $e) {
            $message = $e instanceof \App\Domain\People\Services\DuplicateWarning ? 'A person matching this row now exists. Validate the import again to review the possible duplicate.' : Friendly::message($e, 'This row could not be imported because of an unexpected problem.');
            EmployeeImportRow::query()->whereKey($row->id)->update(['status' => 'error', 'errors' => json_encode([['field' => null, 'message' => $message]], JSON_UNESCAPED_UNICODE)]);
        }
    }
}
