<?php

namespace App\Domain\People\Operations;

use App\Domain\Audit\Audit;
use App\Domain\People\Boot\OperationsBoot;
use App\Domain\People\Models\EmployeeImport;
use App\Domain\People\Models\EmployeeImportRow;
use App\Domain\People\Models\Person;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\People\ProcessEmployeeImport;
use App\Models\User;
use App\Support\Spreadsheet\SpreadsheetException;
use App\Support\Spreadsheet\TabularReader;
use App\Support\Spreadsheet\TabularWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Upload → mapping → validation preview → commit (queued) → retry / rollback for employee imports. */
final class ImportService
{
    public const OPTION_KEYS = ['date_format', 'skip_duplicates', 'allow_duplicates', 'default_country', 'default_timezone', 'default_worker_type'];

    public static function maxRows(): int
    {
        return (int) config('people_operations.import.max_rows', 20000);
    }

    public function stage(User $user, UploadedFile $file, array $options): EmployeeImport
    {
        $reader = TabularReader::open($file->getRealPath(), $file->getClientOriginalName(), ['max_rows' => self::maxRows(), 'max_bytes' => (int) config('people_operations.import.max_kb', 10240) * 1024, 'normalize_headers' => false]);
        $headers = $reader->rawHeaders();
        $import = new EmployeeImport(['user_id' => $user->id, 'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 250), 'status' => 'uploaded', 'headers' => $headers,
            'mapping' => ImportColumns::suggest($headers), 'options' => $this->options($options)]);
        $tenant = app(TenantContext::class)->id;
        $import->uuid = (string) \Illuminate\Support\Str::uuid();
        $ext = $reader->format();
        $path = $tenant.'/'.$import->uuid.'.'.$ext;
        $stream = fopen($file->getRealPath(), 'rb');
        Storage::disk(OperationsBoot::IMPORTS)->put($path, $stream);
        fclose($stream);
        try {
            DB::transaction(function () use ($import, $reader, $path, $tenant) {
                $import->path = $path;
                $import->save();
                $total = 0;
                foreach ($reader->chunks(500) as $chunk) {
                    $batch = [];
                    foreach ($chunk as $number => $row) {
                        $batch[] = ['tenant_id' => $tenant, 'import_id' => $import->id, 'row_number' => $number, 'raw' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
                    }
                    DB::table('employee_import_rows')->insert($batch);
                    $total += count($batch);
                }
                if ($total === 0) {
                    throw new SpreadsheetException('The file has a header row but no data rows.', 'no_rows');
                }
                $import->update(['total_rows' => $total]);
            });
        } catch (\Throwable $e) {
            Storage::disk(OperationsBoot::IMPORTS)->delete($path);
            throw $e;
        }
        Audit::record('import_uploaded', 'people', 'employee-import:'.$import->uuid, ['filename' => $import->filename, 'rows' => $import->total_rows]);

        return $import->refresh();
    }

    public function options(array $options): array
    {
        $o = array_intersect_key($options, array_flip(self::OPTION_KEYS));
        $o['date_format'] = $o['date_format'] ?? 'Y-m-d';
        $o['skip_duplicates'] = (bool) ($o['skip_duplicates'] ?? false);
        $o['allow_duplicates'] = (bool) ($o['allow_duplicates'] ?? false);
        if (! empty($o['default_country'])) {
            $o['default_country'] = strtoupper($o['default_country']);
        }
        if ($o['skip_duplicates'] && $o['allow_duplicates']) {
            throw ValidationException::withMessages(['options' => ['Choose either skip duplicates or allow duplicates, not both.']]);
        }

        return $o;
    }

    /** @param array<string,?string> $mapping target => source header */
    public function setMapping(EmployeeImport $import, array $mapping, ?array $options): EmployeeImport
    {
        abort_unless(in_array($import->status, ['uploaded', 'validated'], true), 409, 'The mapping can only be changed before the import starts.');
        $errors = [];
        $clean = [];
        foreach ($mapping as $target => $source) {
            if ($source === null || $source === '') {
                continue;
            }
            if (! ImportColumns::isKnown((string) $target)) {
                $errors['mapping.'.$target] = ["'".$target."' is not a supported import column."];
            } elseif (! in_array($source, $import->headers ?? [], true)) {
                $errors['mapping.'.$target] = ["The column '".$source."' is not in the uploaded file."];
            } else {
                $clean[$target] = $source;
            }
        }
        if (count($clean) !== count(array_unique($clean))) {
            $errors['mapping'] = ['A file column can only be used for one field.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $import->update(['mapping' => $clean, 'options' => $options !== null ? $this->options($options + ($import->options ?? [])) : $import->options, 'status' => 'uploaded', 'valid_rows' => 0, 'warning_rows' => 0, 'error_rows' => 0]);
        EmployeeImportRow::query()->where('import_id', $import->id)->update(['status' => 'pending', 'errors' => null, 'warnings' => null, 'normalized' => null]);

        return $import->refresh();
    }

    public function missingRequired(EmployeeImport $import): array
    {
        $labels = [];
        foreach (ImportColumns::required() as $key) {
            if (empty(($import->mapping ?? [])[$key])) {
                $labels[] = ImportColumns::COLUMNS[$key][0];
            }
        }

        return $labels;
    }

    /** Validation preview: nothing is written to employee tables. */
    public function validate(EmployeeImport $import): EmployeeImport
    {
        abort_unless(in_array($import->status, ['uploaded', 'validated', 'partial', 'failed'], true), 409, 'This import can no longer be validated.');
        if ($missing = $this->missingRequired($import)) {
            throw ValidationException::withMessages(['mapping' => ['Map a column to: '.implode(', ', $missing).'.']]);
        }
        @set_time_limit(300);
        $validator = new ImportValidator($import);
        $ctx = $this->context($import, $validator);

        $statuses = [];
        $numberRow = [];
        $edges = [];
        $problems = [];
        EmployeeImportRow::query()->where('import_id', $import->id)->orderBy('id')->chunkById(500, function ($rows) use ($validator, $ctx, &$statuses, &$numberRow, &$edges, &$problems, $import) {
            $batch = [];
            foreach ($rows as $row) {
                if ($row->status === 'imported') {
                    $statuses[$row->row_number] = 'imported';

                    continue;
                }
                [$n, $errors, $warnings] = $validator->evaluate($row->raw);
                $this->identity($ctx, $row->row_number, $n, $errors, $warnings);
                $status = $errors ? 'error' : ($warnings ? 'warning' : 'valid');
                $statuses[$row->row_number] = $status;
                if ($num = mb_strtolower($n['employment']['employee_number'] ?? '')) {
                    $numberRow[$num] ??= $row->row_number;
                }
                if (! empty($n['manager']['number']) && ! $n['manager']['employee_id'] && ! $errors) {
                    $edges[$row->row_number] = mb_strtolower($n['manager']['number']);
                }
                $problems[$row->row_number] = [$row->id, $errors, $warnings];
                $batch[] = ['tenant_id' => $row->tenant_id, 'import_id' => $import->id, 'row_number' => $row->row_number, 'raw' => json_encode($row->raw, JSON_UNESCAPED_UNICODE), 'normalized' => json_encode($n, JSON_UNESCAPED_UNICODE),
                    'status' => $status, 'errors' => $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null, 'warnings' => $warnings ? json_encode($warnings, JSON_UNESCAPED_UNICODE) : null, 'created_at' => now(), 'updated_at' => now()];
            }
            $batch && DB::table('employee_import_rows')->upsert($batch, ['import_id', 'row_number'], ['normalized', 'status', 'errors', 'warnings', 'updated_at']);
        });

        // Manager dependencies inside the file: a manager row with errors (or a cycle) blocks its reports.
        $extra = [];
        do {
            $changed = false;
            foreach ($edges as $row => $manager) {
                $managerRow = $numberRow[$manager] ?? null;
                if ($managerRow && ($statuses[$row] ?? null) !== 'error' && ($statuses[$managerRow] ?? null) === 'error') {
                    $extra[$row][] = ['field' => 'manager_employee_number', 'message' => "Manager '".$manager."' is on row ".$managerRow.', which has errors. Fix that row first.'];
                    $statuses[$row] = 'error';
                    $changed = true;
                }
            }
        } while ($changed);
        foreach ($edges as $row => $_) {
            $cursor = $row;
            for ($i = 0; $i <= count($edges); $i++) {
                $next = isset($edges[$cursor]) ? ($numberRow[$edges[$cursor]] ?? null) : null;
                if ($next === null) {
                    break;
                }
                if ($next === $row) {
                    ($statuses[$row] ?? '') !== 'error' && $extra[$row][] = ['field' => 'manager_employee_number', 'message' => 'The manager chain forms a circle. Check the manager columns.'];
                    $statuses[$row] = 'error';

                    break;
                }
                $cursor = $next;
            }
        }
        foreach ($extra as $row => $list) {
            [$id, $errors] = $problems[$row];
            DB::table('employee_import_rows')->where('id', $id)->update(['status' => 'error', 'errors' => json_encode(array_merge($errors, $list), JSON_UNESCAPED_UNICODE)]);
        }

        $this->recount($import);
        $duplicates = EmployeeImportRow::query()->where('import_id', $import->id)->where('status', 'warning')->get(['warnings'])->filter(fn ($r) => collect($r->warnings)->contains('code', 'possible_duplicate'))->count();
        $import->update(['status' => in_array($import->status, ['partial', 'failed'], true) ? $import->status : 'validated', 'options' => ($import->options ?? []) + [] , 'error' => null]);
        $import->update(['options' => array_merge($import->options ?? [], ['duplicate_rows' => $duplicates])]);

        return $import->refresh();
    }

    public function recount(EmployeeImport $import): void
    {
        $c = EmployeeImportRow::query()->where('import_id', $import->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $import->update(['valid_rows' => (int) ($c['valid'] ?? 0), 'warning_rows' => (int) ($c['warning'] ?? 0), 'error_rows' => (int) ($c['error'] ?? 0),
            'imported_rows' => (int) ($c['imported'] ?? 0), 'skipped_rows' => (int) ($c['skipped'] ?? 0), 'total_rows' => (int) $c->sum()]);
    }

    public function commit(EmployeeImport $import, User $user, bool $acceptErrors, string $from = 'validated'): EmployeeImport
    {
        abort_unless($import->status === $from, 409, $from === 'validated' ? 'Validate the import before importing.' : 'This import cannot be retried right now.');
        $ready = $import->valid_rows + $import->warning_rows;
        abort_if($ready === 0, 422, 'There are no valid rows to import.');
        abort_if($import->error_rows > 0 && ! $acceptErrors, 422, $import->error_rows.' row(s) have errors and will not be imported. Fix them, or confirm to import the valid rows only (accept_errors).');
        $o = $import->options ?? [];
        abort_if(($o['duplicate_rows'] ?? 0) > 0 && empty($o['skip_duplicates']) && empty($o['allow_duplicates']), 422,
            ($o['duplicate_rows']).' row(s) look like people who already exist. Choose to skip them (skip_duplicates) or import them anyway (allow_duplicates) in the options.');
        $claimed = EmployeeImport::query()->whereKey($import->id)->where('status', $from)->update(['status' => 'processing', 'started_at' => now(), 'finished_at' => null, 'processed_rows' => 0, 'error' => null]);
        abort_unless($claimed, 409, 'This import is already being processed.');
        Audit::record('import_committed', 'people', 'employee-import:'.$import->uuid, ['ready_rows' => $ready, 'error_rows' => $import->error_rows, 'options' => $o]);
        try {
            ProcessEmployeeImport::dispatch(app(TenantContext::class)->id, $import->id, $user->id);
        } catch (\Throwable $e) {
            report($e);
        }

        return $import->refresh();
    }

    /** Corrects one row's raw values (keyed by file header) and marks it for re-validation. */
    public function patchRow(EmployeeImport $import, int $number, array $values): EmployeeImportRow
    {
        abort_if(in_array($import->status, ['processing', 'rolled_back'], true), 409, 'This import cannot be edited right now.');
        $row = EmployeeImportRow::query()->where('import_id', $import->id)->where('row_number', $number)->firstOrFail();
        abort_if($row->status === 'imported', 409, 'This row was already imported.');
        $raw = $row->raw;
        foreach ($values as $header => $value) {
            abort_unless(in_array($header, $import->headers ?? [], true), 422, "The column '".$header."' is not in the uploaded file.");
            $raw[$header] = $value === null || trim((string) $value) === '' ? null : trim((string) $value);
        }
        $row->update(['raw' => $raw, 'status' => 'pending', 'errors' => null, 'warnings' => null]);
        if ($import->status === 'validated') {
            $import->update(['status' => 'uploaded']);
        }

        return $row->refresh();
    }

    /** Applies a corrected errors.csv: rows are matched by the row_number column. @return array{updated:int,ignored:int} */
    public function applyCorrections(EmployeeImport $import, string $path, string $name): array
    {
        $reader = TabularReader::open($path, $name, ['max_rows' => self::maxRows(), 'normalize_headers' => false]);
        abort_unless(in_array('row_number', $reader->rawHeaders(), true), 422, 'The file must keep the row_number column from the errors file.');
        $updated = $ignored = 0;
        $fileHeaders = array_values(array_intersect($reader->rawHeaders(), $import->headers ?? []));
        foreach ($reader->rows() as $row) {
            $number = (int) ($row['row_number'] ?? 0);
            $target = EmployeeImportRow::query()->where('import_id', $import->id)->where('row_number', $number)->where('status', 'error')->first();
            if (! $target) {
                $ignored++;

                continue;
            }
            $raw = $target->raw;
            foreach ($fileHeaders as $h) {
                $raw[$h] = $row[$h] ?? null;
            }
            $target->update(['raw' => $raw, 'status' => 'pending', 'errors' => null, 'warnings' => null]);
            $updated++;
        }

        return ['updated' => $updated, 'ignored' => $ignored];
    }

    /** Re-validates unresolved rows and re-processes those that are now valid. */
    public function retry(EmployeeImport $import, User $user, ?array $correctionFile): array
    {
        abort_unless(in_array($import->status, ['partial', 'failed', 'completed'], true), 409, 'Only imports that have been processed can be retried.');
        $summary = $correctionFile ? $this->applyCorrections($import, $correctionFile['path'], $correctionFile['name']) : ['updated' => 0, 'ignored' => 0];
        $keep = $import->status;
        $import->update(['status' => 'partial']);
        $this->validate($import->refresh());
        $import->refresh();
        $import->update(['status' => 'partial']);
        if ($import->valid_rows + $import->warning_rows === 0) {
            $import->update(['status' => $keep]);
            abort(422, $import->error_rows.' row(s) still have errors. Correct them and retry.');
        }
        $this->commit($import->refresh(), $user, true, 'partial');

        return $summary;
    }

    public function errorsCsv(EmployeeImport $import): string
    {
        $headers = $import->headers ?? [];
        $path = tempnam(sys_get_temp_dir(), 'err');
        $writer = TabularWriter::open('csv', $path, array_merge(['row_number'], $headers, ['error_reason']));
        EmployeeImportRow::query()->where('import_id', $import->id)->where('status', 'error')->orderBy('row_number')->chunk(500, function ($rows) use ($writer, $headers) {
            foreach ($rows as $row) {
                $reason = collect($row->errors ?? [])->map(fn ($e) => ($e['field'] ? $e['field'].': ' : '').$e['message'])->implode('; ');
                $writer->write(array_merge([$row->row_number], array_map(fn ($h) => $row->raw[$h] ?? null, $headers), [$reason]));
            }
        });
        $writer->close();

        return $path;
    }

    public function discard(EmployeeImport $import): void
    {
        abort_unless(in_array($import->status, ['uploaded', 'validated', 'failed'], true) && $import->imported_rows === 0, 409, 'Only imports that have not created employees can be discarded.');
        DB::transaction(function () use ($import) {
            EmployeeImportRow::query()->where('import_id', $import->id)->delete();
            $import->delete();
        });
        $import->path && Storage::disk(OperationsBoot::IMPORTS)->delete($import->path);
        Audit::record('import_discarded', 'people', 'employee-import:'.$import->uuid, []);
    }

    public function present(EmployeeImport $i, bool $detail = false): array
    {
        $out = ['uuid' => $i->uuid, 'filename' => $i->filename, 'status' => $i->status, 'total_rows' => $i->total_rows, 'counts' => ['valid' => $i->valid_rows, 'warning' => $i->warning_rows, 'error' => $i->error_rows,
            'imported' => $i->imported_rows, 'skipped' => $i->skipped_rows], 'processed_rows' => $i->processed_rows, 'options' => $i->options, 'started_at' => $i->started_at?->toIso8601String(),
            'finished_at' => $i->finished_at?->toIso8601String(), 'rolled_back_at' => $i->rolled_back_at?->toIso8601String(), 'error' => $i->error, 'created_at' => $i->created_at?->toIso8601String(),
            'user_id' => $i->user_id];
        if ($detail) {
            $out['mapping'] = $i->mapping;
            $out['headers'] = $i->headers;
            $out['missing_required'] = $this->missingRequired($i);
        }

        return $out;
    }

    // ---- identity / duplicate checks ---------------------------------------------------------

    private function context(EmployeeImport $import, ImportValidator $validator): object
    {
        $ctx = (object) ['numbers' => [], 'work' => [], 'personal' => [], 'phone' => [], 'namedob' => [], 'existingNumbers' => [], 'existingWork' => [], 'existingPersonal' => [], 'existingPhone' => [], 'existingNameDob' => [],
            'fileNumbers' => []];
        $nums = $works = $personals = $dobs = [];
        $phones = false;
        EmployeeImportRow::query()->where('import_id', $import->id)->where('status', '!=', 'imported')->orderBy('id')->chunkById(1000, function ($rows) use ($validator, $ctx, &$nums, &$works, &$personals, &$dobs, &$phones) {
            foreach ($rows as $row) {
                $m = $validator->mapped($row->raw);
                foreach (['employee_number', 'manager_employee_number'] as $k) {
                    if (! empty($m[$k])) {
                        $nums[mb_strtolower($m[$k])] = true;
                    }
                }
                if (! empty($m['employee_number'])) {
                    $ctx->fileNumbers[mb_strtolower($m['employee_number'])] = true;
                }
                ! empty($m['work_email']) && $works[mb_strtolower($m['work_email'])] = true;
                ! empty($m['personal_email']) && $personals[mb_strtolower($m['personal_email'])] = true;
                ! empty($m['date_of_birth']) && $dobs[$m['date_of_birth']] = true;
                $phones = $phones || ! empty($m['personal_phone']);
            }
        });
        $tenant = app(TenantContext::class)->id;
        foreach (array_chunk(array_keys($nums), 400) as $chunk) {
            foreach (DB::table('employees')->where('tenant_id', $tenant)->whereIn(DB::raw('LOWER(employee_number)'), $chunk)->get(['id', 'employee_number', 'status', 'separated_on', 'deleted_at']) as $e) {
                $ctx->existingNumbers[mb_strtolower($e->employee_number)] = $e;
            }
        }
        $dto = fn ($reason, $confidence, $r) => ['reason' => $reason, 'confidence' => $confidence, 'person_uuid' => $r->person_uuid, 'employee_uuid' => $r->employee_uuid, 'name' => $r->name, 'employee_number' => $r->employee_number];
        $base = fn () => DB::table('people')->leftJoin('employees', fn ($j) => $j->on('employees.person_id', '=', 'people.id')->whereNull('employees.deleted_at'))->where('people.tenant_id', $tenant)->whereNull('people.deleted_at')
            ->select('people.uuid as person_uuid', 'employees.uuid as employee_uuid', 'people.display_name as name', 'employees.employee_number', 'employees.work_email', 'people.personal_email', 'people.personal_phone', 'people.legal_name', 'people.date_of_birth');
        foreach (array_chunk(array_keys($works), 400) as $chunk) {
            foreach ($base()->whereIn(DB::raw('LOWER(employees.work_email)'), $chunk)->get() as $r) {
                $ctx->existingWork[mb_strtolower($r->work_email)][] = $dto('Same work email', 'high', $r);
            }
        }
        foreach (array_chunk(array_keys($personals), 400) as $chunk) {
            foreach ($base()->whereIn(DB::raw('LOWER(people.personal_email)'), $chunk)->get() as $r) {
                $ctx->existingPersonal[mb_strtolower($r->personal_email)][] = $dto('Same personal email', 'high', $r);
            }
        }
        foreach (array_chunk(array_keys($dobs), 400) as $chunk) {
            foreach ($base()->whereIn('people.date_of_birth', $chunk)->get() as $r) {
                $ctx->existingNameDob[mb_strtolower($r->legal_name).'|'.substr((string) $r->date_of_birth, 0, 10)][] = $dto('Same name and date of birth', 'high', $r);
            }
        }
        if ($phones) {
            foreach ($base()->whereNotNull('people.personal_phone')->get() as $r) {
                $digits = preg_replace('/\D+/', '', $r->personal_phone);
                strlen($digits) >= 7 && $ctx->existingPhone[$digits][] = $dto('Same personal phone', 'medium', $r);
            }
        }

        return $ctx;
    }

    private function identity(object $ctx, int $rowNumber, array &$n, array &$errors, array &$warnings): void
    {
        $matches = [];
        $number = $n['employment']['employee_number'] ?? null;
        if ($number !== null) {
            $key = mb_strtolower($number);
            if (isset($ctx->existingNumbers[$key])) {
                $errors[] = ['field' => 'employee_number', 'message' => "Employee number '".$number."' is already in use."];
            } elseif (isset($ctx->numbers[$key])) {
                $errors[] = ['field' => 'employee_number', 'message' => "Employee number '".$number."' is also used on row ".$ctx->numbers[$key].'.'];
            }
            $ctx->numbers[$key] ??= $rowNumber;
        }
        if ($email = $n['employment']['work_email'] ?? null) {
            $key = mb_strtolower($email);
            if (isset($ctx->work[$key])) {
                $errors[] = ['field' => 'work_email', 'message' => "Work email '".$email."' is also used on row ".$ctx->work[$key].'.'];
            }
            $ctx->work[$key] ??= $rowNumber;
            $matches = array_merge($matches, $ctx->existingWork[$key] ?? []);
        }
        if ($email = $n['person']['personal_email'] ?? null) {
            $key = mb_strtolower($email);
            isset($ctx->personal[$key]) && $matches[] = ['reason' => 'Same personal email', 'confidence' => 'high', 'row_number' => $ctx->personal[$key]];
            $ctx->personal[$key] ??= $rowNumber;
            $matches = array_merge($matches, $ctx->existingPersonal[$key] ?? []);
        }
        if (($digits = preg_replace('/\D+/', '', $n['person']['personal_phone'] ?? '')) && strlen($digits) >= 7) {
            isset($ctx->phone[$digits]) && $matches[] = ['reason' => 'Same personal phone', 'confidence' => 'medium', 'row_number' => $ctx->phone[$digits]];
            $ctx->phone[$digits] ??= $rowNumber;
            $matches = array_merge($matches, $ctx->existingPhone[$digits] ?? []);
        }
        if (! empty($n['person']['date_of_birth'])) {
            $legal = mb_strtolower(trim(implode(' ', array_filter([$n['person']['legal_first_name'] ?? '', $n['person']['legal_middle_name'] ?? '', $n['person']['legal_last_name'] ?? '']))));
            $key = $legal.'|'.$n['person']['date_of_birth'];
            isset($ctx->namedob[$key]) && $matches[] = ['reason' => 'Same name and date of birth', 'confidence' => 'high', 'row_number' => $ctx->namedob[$key]];
            $ctx->namedob[$key] ??= $rowNumber;
            $matches = array_merge($matches, $ctx->existingNameDob[$key] ?? []);
        }
        if ($matches) {
            $text = collect($matches)->map(fn ($m) => (isset($m['row_number']) ? 'row '.$m['row_number'].' of this file' : $m['name'].($m['employee_number'] ? ' ('.$m['employee_number'].')' : '')).': '.strtolower($m['reason']))->unique()->implode('; ');
            $warnings[] = ['field' => null, 'code' => 'possible_duplicate', 'message' => 'Possible duplicate of '.$text.'.', 'matches' => array_values($matches)];
        }
        // manager
        if ($number = $n['manager']['number'] ?? null) {
            $key = mb_strtolower($number);
            $own = mb_strtolower($n['employment']['employee_number'] ?? '');
            $existing = $ctx->existingNumbers[$key] ?? null;
            if ($own !== '' && $own === $key) {
                $errors[] = ['field' => 'manager_employee_number', 'message' => 'An employee cannot be their own manager.'];
            } elseif ($existing) {
                if ($existing->deleted_at || in_array($existing->status, ['separated', 'archived', 'inactive'], true)) {
                    $errors[] = ['field' => 'manager_employee_number', 'message' => "Manager '".$number."' is not currently employed."];
                } else {
                    $n['manager']['employee_id'] = $existing->id;
                }
            } elseif (! isset($ctx->fileNumbers[$key])) {
                $errors[] = ['field' => 'manager_employee_number', 'message' => "Manager '".$number."' was not found in the system or in this file."];
            }
        }
    }
}
