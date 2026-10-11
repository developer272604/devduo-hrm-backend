<?php

namespace App\Domain\Attendance\Capture\Import;

use App\Domain\Attendance\Boot\CaptureBoot;
use App\Domain\Attendance\Capture\PunchRejected;
use App\Domain\Attendance\Capture\PunchService;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendanceImport;
use App\Domain\Attendance\Models\AttendanceImportRow;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Audit\Audit;
use App\Models\User;
use App\Support\Spreadsheet\SpreadsheetException;
use App\Support\Spreadsheet\TabularReader;
use App\Support\Spreadsheet\TabularWriter;
use App\Support\Spreadsheet\Template;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Attendance / device-event import pipeline: upload -> mapping -> validate (preview) -> commit (queued) -> errors.csv -> retry.
 * Original values are preserved in attendance_import_rows.raw and in attendance_events.raw_metadata.import.original.
 */
class AttendanceImportService
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ROWS = 50000;

    public const OPTIONS = ['timezone', 'date_format'];

    private const SYNONYMS = [
        'employee_number' => ['employee_number', 'employee_no', 'emp_no', 'emp_id', 'employee_id', 'staff_id', 'employee_code', 'emp_code'],
        'work_email' => ['work_email', 'email', 'email_address'],
        'device_employee_id' => ['device_employee_id', 'device_user_id', 'user_id', 'badge', 'badge_id', 'card_no', 'pin', 'enroll_id'],
        'timestamp' => ['timestamp', 'punch_time', 'punched_at', 'datetime', 'date_time', 'event_time', 'time_stamp'],
        'date' => ['date', 'punch_date', 'attendance_date'],
        'time' => ['time', 'punch_time_only', 'in_time'],
        'direction' => ['direction', 'type', 'punch_type', 'in_out', 'status', 'event_type'],
        'external_ref' => ['external_event_id', 'external_ref', 'event_id', 'transaction_id', 'log_id', 'id'],
        'device' => ['device', 'device_id', 'terminal', 'terminal_id', 'machine'],
        'location' => ['location', 'site', 'branch'],
        'timezone' => ['timezone', 'time_zone', 'tz'],
    ];

    public function __construct(private PunchService $punches) {}

    // ---- upload ---------------------------------------------------------------------------
    public function upload(UploadedFile $file, string $kind, ?AttendanceDevice $device, User $user): AttendanceImport
    {
        if (! in_array($kind, ['punches', 'device_events'], true)) {
            throw ValidationException::withMessages(['kind' => ['Choose "punches" or "device_events".']]);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw SpreadsheetException::tooLarge(self::MAX_BYTES);
        }
        $reader = TabularReader::open($file->getPathname(), $file->getClientOriginalName(), ['max_rows' => self::MAX_ROWS, 'max_bytes' => self::MAX_BYTES]);
        $total = $reader->count();
        if ($total === 0) {
            throw new SpreadsheetException('The file has a header row but no data rows.', 'empty_file');
        }
        $uuid = (string) Str::uuid();
        $path = 'imports/'.$user->tenant_id.'/'.$uuid.'.'.$reader->format();
        Storage::disk(CaptureBoot::DISK)->put($path, file_get_contents($file->getPathname()));

        return AttendanceImport::query()->create([
            'uuid' => $uuid, 'user_id' => $user->id, 'kind' => $kind, 'device_id' => $device?->id, 'filename' => mb_substr($file->getClientOriginalName(), 0, 250), 'path' => $path,
            'status' => 'uploaded', 'mapping' => $this->suggestMapping($reader->headers()), 'options' => [], 'total_rows' => $total,
        ]);
    }

    public function reader(AttendanceImport $import): TabularReader
    {
        $path = Storage::disk(CaptureBoot::DISK)->path($import->path);

        return TabularReader::open($path, $import->filename, ['max_rows' => self::MAX_ROWS, 'max_bytes' => self::MAX_BYTES]);
    }

    /** @return array{columns:array<int,array{key:string,label:string}>,sample:array,suggested_mapping:array} */
    public function preview(AttendanceImport $import): array
    {
        $reader = $this->reader($import);
        $columns = [];
        foreach ($reader->headers() as $i => $key) {
            $columns[] = ['key' => $key, 'label' => $reader->rawHeaders()[$i] ?? $key];
        }

        return ['columns' => $columns, 'sample' => array_values($reader->sample(5)), 'suggested_mapping' => $this->suggestMapping($reader->headers())];
    }

    public function suggestMapping(array $headers): array
    {
        $mapping = [];
        foreach (self::SYNONYMS as $field => $names) {
            foreach ($names as $name) {
                if (in_array($name, $headers, true) && ! in_array($name, $mapping, true)) {
                    $mapping[$field] = $name;
                    break;
                }
            }
        }
        // a combined timestamp column wins over separate date/time
        if (isset($mapping['timestamp'])) {
            unset($mapping['date'], $mapping['time']);
        }

        return $mapping;
    }

    // ---- mapping --------------------------------------------------------------------------
    public function saveMapping(AttendanceImport $import, array $mapping, array $options): AttendanceImport
    {
        if (! in_array($import->status, ['uploaded', 'mapped', 'validated', 'failed'], true)) {
            throw new PunchRejected('import_busy', 'This import is already being processed.', 409);
        }
        $headers = $this->reader($import)->headers();
        $clean = [];
        foreach ($mapping as $field => $column) {
            if (! in_array($field, ImportRowValidator::FIELDS, true) || $column === null || $column === '') {
                continue;
            }
            if (! in_array($column, $headers, true)) {
                throw ValidationException::withMessages(["mapping.$field" => ['The column "'.$column.'" is not in the file.']]);
            }
            $clean[$field] = $column;
        }
        if ($problems = ImportRowValidator::mappingProblems($clean, $import->kind)) {
            throw ValidationException::withMessages(['mapping' => $problems]);
        }
        $options = array_intersect_key($options, array_flip(self::OPTIONS));
        if (! empty($options['timezone']) && ! TimeZones::isValid($options['timezone'])) {
            throw ValidationException::withMessages(['options.timezone' => ['Use an IANA timezone such as Asia/Kolkata.']]);
        }
        $options = array_filter($options, fn ($v) => $v !== null && $v !== '');
        $import->forceFill(['mapping' => $clean, 'options' => $options, 'status' => 'mapped'])->save();

        return $import;
    }

    // ---- validate -------------------------------------------------------------------------
    public function validate(AttendanceImport $import): AttendanceImport
    {
        if (! $import->mapping || ImportRowValidator::mappingProblems($import->mapping, $import->kind)) {
            throw new PunchRejected('mapping_required', 'Map the file columns first.');
        }
        AttendanceImportRow::query()->where('import_id', $import->id)->where('status', '!=', 'imported')->delete();
        $import->forceFill(['status' => 'validating'])->save();
        $validator = new ImportRowValidator($import, $this->punches);
        $tenantId = $import->tenant_id;
        foreach ($this->reader($import)->chunks(500) as $rows) {
            $results = $validator->validateChunk($rows);
            $insert = [];
            foreach ($results as $number => $r) {
                $insert[] = ['tenant_id' => $tenantId, 'import_id' => $import->id, 'row_number' => $number, 'raw' => json_encode($rows[$number], JSON_UNESCAPED_UNICODE), 'normalized' => $r['normalized'] ? json_encode($r['normalized']) : null,
                    'status' => $r['status'], 'errors' => $r['errors'] ? json_encode($r['errors']) : null, 'warnings' => $r['warnings'] ? json_encode($r['warnings']) : null, 'created_at' => now(), 'updated_at' => now()];
            }
            DB::table('attendance_import_rows')->insert($insert);
        }
        $this->recount($import);
        $import->forceFill(['status' => 'validated'])->save();

        return $import;
    }

    public function recount(AttendanceImport $import): void
    {
        $counts = AttendanceImportRow::query()->where('import_id', $import->id)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $import->forceFill([
            'total_rows' => (int) $counts->sum(), 'valid_rows' => (int) ($counts['valid'] ?? 0), 'warning_rows' => (int) ($counts['warning'] ?? 0), 'error_rows' => (int) ($counts['error'] ?? 0),
            'imported_rows' => (int) ($counts['imported'] ?? 0), 'duplicate_rows' => (int) ($counts['duplicate'] ?? 0),
        ])->save();
    }

    // ---- commit ---------------------------------------------------------------------------
    /** Runs inside the queued job (tenant context already set). Idempotent: imported rows are skipped. */
    public function commit(AttendanceImport $import, ?int $requestedBy = null): AttendanceImport
    {
        if (! in_array($import->status, ['queued', 'importing', 'validated', 'completed_with_errors'], true)) {
            return $import;
        }
        $import->forceFill(['status' => 'importing', 'started_at' => $import->started_at ?? now()])->save();
        $employees = [];
        $dates = [];
        $device = $import->device_id ? AttendanceDevice::query()->find($import->device_id) : null;
        try {
            AttendanceImportRow::query()->where('import_id', $import->id)->whereIn('status', ['valid', 'warning'])->orderBy('id')->chunkById(500, function ($rows) use ($import, &$employees, &$dates, $device) {
                foreach ($rows as $row) {
                    $n = $row->normalized;
                    $rowDevice = ($n['device_id'] ?? null) ? ($device && $device->id === $n['device_id'] ? $device : AttendanceDevice::query()->find($n['device_id'])) : null;
                    try {
                        $employee = \App\Domain\People\Models\Employee::query()->findOrFail($n['employee_id']);
                        $result = $this->punches->record([
                            'employee' => $employee, 'source' => 'import', 'direction' => $n['direction'], 'at' => $n['punched_at'], 'device' => $rowDevice, 'location_id' => $n['location_id'] ?? null,
                            'external_ref' => $n['external_ref'], 'import_id' => $import->id, 'allow_old' => true, 'recalculate' => false, 'emit_events' => false,
                            'idempotency_key' => 'imp:'.sha1($employee->id.'|'.$n['punched_at'].'|'.$n['direction'].'|'.($n['external_ref'] ?? '').'|'.($rowDevice?->id ?? '')),
                            'metadata' => ['import' => ['file' => $import->filename, 'row' => $row->row_number, 'original' => $row->raw, 'timezone' => $n['timezone'], 'timezone_source' => $n['timezone_source']]],
                        ]);
                        $row->forceFill(['status' => $result->duplicate ? 'duplicate' : 'imported', 'event_id' => $result->event->id,
                            'warnings' => $result->duplicate ? array_merge($row->warnings ?? [], ['This punch already exists and was skipped.']) : $row->warnings])->save();
                        if (! $result->duplicate) {
                            $employees[$employee->id] = true;
                            $dates[] = $n['local_date'];
                        }
                    } catch (PunchRejected|HttpException $e) {
                        $row->forceFill(['status' => 'error', 'errors' => [$e->getMessage() ?: 'The attendance period is locked.']])->save();
                    } catch (\Throwable $e) {
                        report($e);
                        $row->forceFill(['status' => 'error', 'errors' => ['This row could not be imported. Try again; if it keeps failing, contact support.']])->save();
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('attendance.import_failed', ['import' => $import->uuid, 'tenant_id' => $import->tenant_id, 'error' => $e->getMessage()]);
            $import->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
            $this->recount($import);

            throw $e;
        }
        $this->recount($import);
        $import->forceFill(['status' => $import->error_rows > 0 ? 'completed_with_errors' : 'completed', 'finished_at' => now()])->save();
        if ($employees && $dates) {
            $this->punches->requestBatchRecalculation(array_keys($employees), min($dates), max($dates), 'import', $requestedBy);
        }
        Audit::record('attendance_import_committed', 'attendance', 'attendance_import:'.$import->uuid, ['kind' => $import->kind, 'file' => $import->filename, 'imported' => $import->imported_rows,
            'duplicates' => $import->duplicate_rows, 'errors' => $import->error_rows, 'user_id' => $requestedBy ?? $import->user_id]);

        return $import;
    }

    /** Re-validates the rows that failed (mappings or employees may have been fixed since), then commits them again. */
    public function revalidateFailed(AttendanceImport $import): void
    {
        $validator = new ImportRowValidator($import, $this->punches);
        AttendanceImportRow::query()->where('import_id', $import->id)->where('status', 'error')->orderBy('id')->chunkById(500, function ($rows) use ($validator) {
            $raws = $rows->mapWithKeys(fn ($r) => [$r->row_number => $r->raw])->all();
            $results = $validator->validateChunk($raws);
            foreach ($rows as $row) {
                $r = $results[$row->row_number];
                $row->forceFill(['status' => $r['status'], 'normalized' => $r['normalized'], 'errors' => $r['errors'] ?: null, 'warnings' => $r['warnings'] ?: null])->save();
            }
        });
        $this->recount($import);
    }

    // ---- files ----------------------------------------------------------------------------
    /** @return array{0:string,1:string} [path, filename] of errors.csv (row number, problems, original columns) */
    public function errorsFile(AttendanceImport $import): array
    {
        $first = AttendanceImportRow::query()->where('import_id', $import->id)->where('status', 'error')->orderBy('row_number')->first();
        $columns = array_keys($first?->raw ?? []);
        $path = tempnam(sys_get_temp_dir(), 'imperr');
        $writer = TabularWriter::open('csv', $path, array_merge(['row_number', 'problems'], $columns ?: ['values']));
        AttendanceImportRow::query()->where('import_id', $import->id)->where('status', 'error')->orderBy('row_number')->chunk(500, function ($rows) use ($writer, $columns) {
            foreach ($rows as $row) {
                $writer->write(array_merge([$row->row_number, implode(' ', $row->errors ?? [])], array_map(fn ($c) => $row->raw[$c] ?? null, $columns)));
            }
        });
        $writer->close();

        return [$path, 'attendance-import-errors-'.substr($import->uuid, 0, 8).'.csv'];
    }

    public static function templateColumns(string $kind): array
    {
        return $kind === 'device_events'
            ? ['device_employee_id', 'timestamp', 'direction', 'external_event_id']
            : ['employee_number', 'timestamp', 'direction', 'external_event_id', 'timezone', 'location'];
    }

    public static function templateExample(string $kind): array
    {
        return $kind === 'device_events'
            ? [['1001', '2026-10-07 09:02:00', 'IN', 'EV-1'], ['1001', '2026-10-07 18:05:00', 'OUT', 'EV-2']]
            : [['EMP-00001', '2026-10-07 09:02:00', 'IN', '', 'Asia/Kolkata', 'CHN'], ['EMP-00001', '2026-10-07 18:05:00', 'OUT', '', 'Asia/Kolkata', 'CHN']];
    }

    public function template(string $kind, string $format)
    {
        return Template::download(self::templateColumns($kind), self::templateExample($kind), $format, 'attendance-'.($kind === 'device_events' ? 'device-events' : 'punches').'-template');
    }

    public function present(AttendanceImport $import): array
    {
        return [
            'id' => $import->uuid, 'kind' => $import->kind, 'filename' => $import->filename, 'status' => $import->status, 'mapping' => $import->mapping, 'options' => $import->options,
            'device_id' => $import->device_id ? AttendanceDevice::query()->whereKey($import->device_id)->value('uuid') : null,
            'counts' => ['total' => $import->total_rows, 'valid' => $import->valid_rows, 'warnings' => $import->warning_rows, 'errors' => $import->error_rows, 'imported' => $import->imported_rows, 'duplicates' => $import->duplicate_rows],
            'progress_percent' => $import->total_rows ? (int) round(100 * ($import->imported_rows + $import->duplicate_rows + $import->error_rows) / max(1, $import->total_rows)) : 0,
            'started_at' => $import->started_at?->toIso8601String(), 'finished_at' => $import->finished_at?->toIso8601String(), 'created_at' => $import->created_at?->toIso8601String(),
            'message' => $this->message($import),
        ];
    }

    private function message(AttendanceImport $import): ?string
    {
        if ($import->status === 'validated' && $import->error_rows > 0) {
            return "We couldn't match or read {$import->error_rows} row(s). Download the error file to fix them, or import the rest.";
        }
        if ($import->status === 'completed_with_errors') {
            return "Imported {$import->imported_rows} punch(es). {$import->error_rows} row(s) failed: download the error file, correct them and upload again.";
        }
        if ($import->status === 'completed') {
            return "Imported {$import->imported_rows} punch(es); {$import->duplicate_rows} duplicate(s) were skipped.";
        }
        if ($import->status === 'failed') {
            return 'The import stopped unexpectedly. Your data is safe; use Retry to continue.';
        }

        return null;
    }
}
