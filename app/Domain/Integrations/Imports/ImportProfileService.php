<?php

namespace App\Domain\Integrations\Imports;

use App\Domain\Attendance\Capture\Import\AttendanceImportService;
use App\Domain\Attendance\Capture\Import\ImportRowValidator;
use App\Domain\Attendance\Models\AttendanceImport;
use App\Domain\Attendance\Models\AttendanceImportRow;
use App\Domain\Audit\Audit;
use App\Domain\Integrations\Models\ImportProfile;
use App\Domain\Integrations\Models\JobItem;
use App\Domain\Integrations\Models\JobRun;
use App\Domain\People\Models\EmployeeImport;
use App\Domain\People\Models\EmployeeImportRow;
use App\Domain\People\Operations\ImportColumns;
use App\Domain\People\Operations\ImportService as PeopleImportService;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Support\Spreadsheet\SpreadsheetException;
use App\Support\Spreadsheet\TabularReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Local, vendor-free inbound adapters: a profile maps a CSV/JSON file onto an EXISTING import pipeline (attendance punches or employee master).
 * Nothing here talks to a network. A run is idempotent per (profile, file hash, dry-run flag); dry runs validate only and write nothing.
 */
final class ImportProfileService
{
    public const KINDS = ['attendance_punches' => 'Attendance punches', 'employee_master' => 'Employee master'];

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_AUTO_ATTEMPTS = 3;

    public function __construct(private AttendanceImportService $attendance, private PeopleImportService $people) {}

    // ---- profiles ------------------------------------------------------------------------
    public function profiles(): array
    {
        return ImportProfile::query()->latest('id')->get()->map(fn ($p) => $this->present($p))->all();
    }

    public function create(User $actor, array $input): array
    {
        $data = $this->validated($input);
        $profile = ImportProfile::create($data + ['created_by' => $actor->id, 'status' => 'active', 'next_run_at' => $data['source'] === 'drop' && ! empty($data['interval_minutes']) ? now() : null]);
        Audit::record('integration.import_profile.created', 'integrations', 'import_profile:'.$profile->uuid, ['kind' => $profile->kind]);

        return $this->present($profile);
    }

    public function update(User $actor, string $uuid, array $input): array
    {
        $profile = ImportProfile::byUuid($uuid);
        $data = $this->validated($input + ['name' => $profile->name, 'kind' => $profile->kind, 'format' => $profile->format, 'mapping' => $profile->mapping ?? [], 'options' => $profile->options ?? [],
            'source' => $profile->source, 'interval_minutes' => $profile->interval_minutes]);
        $profile->update($data + (isset($input['status']) && in_array($input['status'], ['active', 'disabled'], true) ? ['status' => $input['status']] : []));
        Audit::record('integration.import_profile.updated', 'integrations', 'import_profile:'.$profile->uuid);

        return $this->present($profile->refresh());
    }

    public function dropPath(ImportProfile $profile): string
    {
        return 'integrations/'.$profile->tenant_id.'/drop/'.$profile->uuid;
    }

    public function present(ImportProfile $p): array
    {
        $last = JobRun::query()->where('profile_id', $p->id)->latest('id')->first();

        return ['uuid' => $p->uuid, 'name' => $p->name, 'kind' => $p->kind, 'kind_label' => self::KINDS[$p->kind] ?? $p->kind, 'format' => $p->format, 'mapping' => $p->mapping ?? [], 'options' => $p->options ?? [],
            'source' => $p->source, 'status' => $p->status, 'interval_minutes' => $p->interval_minutes, 'next_run_at' => $p->next_run_at?->toIso8601String(), 'last_run_at' => $p->last_run_at?->toIso8601String(),
            'drop_folder' => $p->source === 'drop' ? $this->dropPath($p) : null, 'last_run_status' => $last?->status, 'created_at' => $p->created_at?->toIso8601String()];
    }

    // ---- runs ----------------------------------------------------------------------------
    public function runs(?string $profileUuid = null): array
    {
        $profile = $profileUuid ? ImportProfile::byUuid($profileUuid) : null;

        return JobRun::query()->when($profile, fn ($q) => $q->where('profile_id', $profile->id))->latest('id')->paginate(25)->through(fn ($r) => $this->presentRun($r))->toArray();
    }

    public function items(string $runUuid): array
    {
        $run = JobRun::byUuid($runUuid);

        return JobItem::query()->where('run_id', $run->id)->orderBy('row_number')->paginate(50)->through(fn ($i) => $i->only(['row_number', 'status', 'message']))->toArray();
    }

    /** Runs an uploaded file through the profile. Re-sending the same file (same hash) returns the existing run. */
    public function runUpload(User $actor, string $profileUuid, UploadedFile $file, bool $dryRun): array
    {
        $profile = ImportProfile::byUuid($profileUuid);
        abort_if($profile->status !== 'active', 409, 'This import profile is disabled.');
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => ['The file is larger than 10 MB.']]);
        }

        return $this->presentRun($this->run($profile, file_get_contents($file->getRealPath()), $file->getClientOriginalName(), $dryRun, 'manual', $actor));
    }

    /** @param  string  $content  raw file bytes */
    public function run(ImportProfile $profile, string $content, string $name, bool $dryRun, string $trigger, ?User $actor): JobRun
    {
        $hash = hash('sha256', $content);
        $key = $profile->id.':'.$hash.':'.($dryRun ? 'dry' : 'live');
        $existing = JobRun::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }
        $uuid = (string) Str::uuid();
        $path = 'integrations/'.$profile->tenant_id.'/runs/'.$uuid.'.'.($profile->format === 'json' ? 'json' : 'csv');
        Storage::disk('local')->put($path, $content);
        $run = JobRun::create(['uuid' => $uuid, 'profile_id' => $profile->id, 'idempotency_key' => $key, 'trigger' => $trigger, 'dry_run' => $dryRun, 'status' => 'pending',
            'file_name' => mb_substr(basename($name), 0, 250), 'file_path' => $path, 'file_hash' => $hash, 'started_by' => $actor?->id ?? $profile->created_by]);
        Audit::record('integration.import_run.started', 'integrations', 'import_run:'.$run->uuid, ['profile' => $profile->uuid, 'dry_run' => $dryRun, 'trigger' => $trigger]);

        return $this->execute($run);
    }

    /** Manual retry of a failed run (same stored file, same idempotency key). */
    public function retry(User $actor, string $runUuid): array
    {
        $run = JobRun::byUuid($runUuid);
        abort_unless(in_array($run->status, ['failed'], true), 409, 'Only failed runs can be retried.');
        Audit::record('integration.import_run.retried', 'integrations', 'import_run:'.$run->uuid);

        return $this->presentRun($this->execute($run));
    }

    public function execute(JobRun $run): JobRun
    {
        $profile = ImportProfile::query()->findOrFail($run->profile_id);
        $run->forceFill(['status' => 'running', 'attempts' => $run->attempts + 1, 'started_at' => now(), 'finished_at' => null, 'error_code' => null, 'error_message' => null, 'next_retry_at' => null])->save();
        JobItem::query()->where('run_id', $run->id)->delete();
        $tmp = null;
        try {
            $user = User::query()->where('tenant_id', $run->tenant_id)->where('active', true)->find($run->started_by ?? $profile->created_by);
            if (! $user) {
                throw new RunFailed('owner_inactive', 'The user who owns this run is no longer active.', false);
            }
            $tmp = $this->materialize($profile, Storage::disk('local')->get($run->file_path));
            $file = new UploadedFile($tmp, pathinfo($run->file_name, PATHINFO_FILENAME).'.csv', null, null, true);
            $profile->kind === 'attendance_punches' ? $this->runAttendance($run, $profile, $file, $user) : $this->runEmployees($run, $profile, $file, $user);
        } catch (RunFailed $e) {
            $run->forceFill(['status' => 'failed', 'error_code' => $e->codeName, 'error_message' => mb_substr($e->getMessage(), 0, 400), 'finished_at' => now(),
                'next_retry_at' => $e->retryable && $run->attempts < self::MAX_AUTO_ATTEMPTS ? now()->addMinutes(5 * $run->attempts) : null])->save();
        } catch (SpreadsheetException $e) {
            $run->forceFill(['status' => 'failed', 'error_code' => 'invalid_file', 'error_message' => mb_substr($e->getMessage(), 0, 400), 'finished_at' => now()])->save();
        } catch (ValidationException $e) {
            $run->forceFill(['status' => 'failed', 'error_code' => 'invalid_input', 'error_message' => mb_substr(collect($e->errors())->flatten()->implode(' '), 0, 400), 'finished_at' => now()])->save();
        } catch (HttpException $e) {
            $run->forceFill(['status' => 'failed', 'error_code' => 'rejected', 'error_message' => mb_substr($e->getMessage(), 0, 400), 'finished_at' => now()])->save();
        } catch (\Throwable $e) {
            report($e);
            $run->forceFill(['status' => 'failed', 'error_code' => 'exception', 'error_message' => 'The run stopped unexpectedly and can be retried.', 'finished_at' => now(),
                'next_retry_at' => $run->attempts < self::MAX_AUTO_ATTEMPTS ? now()->addMinutes(5 * $run->attempts) : null])->save();
        } finally {
            if ($tmp && is_file($tmp)) {
                @unlink($tmp);
            }
        }
        $profile->forceFill(['last_run_at' => now()])->save();

        return $run->refresh();
    }

    /** Completes runs whose underlying employee import was committed through a real (async) queue. */
    public function finalize(JobRun $run): JobRun
    {
        if ($run->status !== 'processing' || ! $run->target_import_uuid) {
            return $run;
        }
        $import = EmployeeImport::query()->where('uuid', $run->target_import_uuid)->first();
        if ($import && in_array($import->status, ['completed', 'partial', 'failed'], true)) {
            $run->forceFill(['status' => $import->status === 'failed' ? 'failed' : ($import->status === 'partial' ? 'completed_with_errors' : 'completed'), 'imported_rows' => $import->imported_rows,
                'error_rows' => $import->error_rows, 'finished_at' => now(), 'error_code' => $import->status === 'failed' ? 'import_failed' : null])->save();
        }

        return $run;
    }

    // ---- adapters ------------------------------------------------------------------------
    private function runAttendance(JobRun $run, ImportProfile $profile, UploadedFile $file, User $user): void
    {
        $import = $this->attendance->upload($file, 'punches', null, $user);
        $reader = $this->attendance->reader($import);
        $mapping = $this->resolveMapping($profile->mapping ?? [], $reader->headers(), ImportRowValidator::FIELDS) ?: $import->mapping;
        $this->attendance->saveMapping($import, $mapping, array_intersect_key($profile->options ?? [], array_flip(AttendanceImportService::OPTIONS)));
        $this->attendance->validate($import);
        $import->refresh();
        $run->forceFill(['target_import_uuid' => $import->uuid, 'total_rows' => $import->total_rows, 'valid_rows' => $import->valid_rows + $import->warning_rows, 'error_rows' => $import->error_rows])->save();
        AttendanceImportRow::query()->where('import_id', $import->id)->where('status', 'error')->orderBy('row_number')->limit(200)->get()
            ->each(fn ($r) => JobItem::create(['run_id' => $run->id, 'row_number' => $r->row_number, 'status' => 'error', 'message' => mb_substr(implode(' ', $r->errors ?? []), 0, 500)]));
        if ($run->dry_run) {
            $run->forceFill(['status' => $import->error_rows ? 'dry_run_errors' : 'dry_run_ok', 'finished_at' => now()])->save();

            return;
        }
        if ($import->error_rows > 0 && empty($profile->options['accept_errors'])) {
            throw new RunFailed('validation_errors', $import->error_rows.' row(s) have errors. Fix the file, or enable accept_errors to import only the valid rows.', false);
        }
        $this->attendance->commit($import, $user->id);
        $import->refresh();
        $run->forceFill(['status' => $import->error_rows ? 'completed_with_errors' : 'completed', 'imported_rows' => $import->imported_rows, 'error_rows' => $import->error_rows, 'finished_at' => now()])->save();
    }

    private function runEmployees(JobRun $run, ImportProfile $profile, UploadedFile $file, User $user): void
    {
        $import = $this->people->stage($user, $file, $profile->options ?? []);
        if ($profile->mapping) {
            $mapping = $this->resolveMapping($profile->mapping, $import->headers ?? [], null, true);
            $import = $this->people->setMapping($import, $mapping, null);
        }
        $import = $this->people->validate($import)->refresh();
        $run->forceFill(['target_import_uuid' => $import->uuid, 'total_rows' => $import->total_rows, 'valid_rows' => $import->valid_rows + $import->warning_rows, 'error_rows' => $import->error_rows])->save();
        EmployeeImportRow::query()->where('import_id', $import->id)->where('status', 'error')->orderBy('row_number')->limit(200)->get()
            ->each(fn ($r) => JobItem::create(['run_id' => $run->id, 'row_number' => $r->row_number, 'status' => 'error', 'message' => mb_substr(implode(' ', (array) ($r->errors ?? [])), 0, 500)]));
        if ($run->dry_run) {
            $run->forceFill(['status' => $import->error_rows ? 'dry_run_errors' : 'dry_run_ok', 'finished_at' => now()])->save();

            return;
        }
        $import = $this->people->commit($import, $user, ! empty($profile->options['accept_errors']))->refresh();
        $run->forceFill(['status' => 'processing'])->save();
        if (in_array($import->status, ['completed', 'partial', 'failed'], true)) {
            $this->finalize($run->refresh());
        }
    }

    /** Applies the profile mapping against the file's real headers (matched case/punctuation-insensitively). */
    private function resolveMapping(array $mapping, array $headers, ?array $allowedFields, bool $targetIsKey = true): array
    {
        $norm = fn (string $h) => TabularReader::normalizeHeader($h);
        $byNorm = [];
        foreach ($headers as $h) {
            $byNorm[$norm($h)] ??= $h;
        }
        $out = [];
        foreach ($mapping as $field => $column) {
            if ($allowedFields !== null && ! in_array($field, $allowedFields, true)) {
                continue;
            }
            if (isset($byNorm[$norm((string) $column)])) {
                $out[$field] = $byNorm[$norm((string) $column)];
            }
        }

        return $out;
    }

    /** CSV is used as is; JSON (array of flat objects, or {"data":[...]}) is converted to a temporary CSV. */
    private function materialize(ImportProfile $profile, string $content): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ivt');
        if ($profile->format !== 'json') {
            file_put_contents($tmp, $content);

            return $tmp;
        }
        $rows = json_decode($content, true);
        $rows = is_array($rows) && isset($rows['data']) && is_array($rows['data']) ? $rows['data'] : $rows;
        if (! is_array($rows) || ! array_is_list($rows) || ! $rows || ! is_array($rows[0])) {
            throw new RunFailed('invalid_json', 'The JSON file must be an array of flat objects, or {"data": [...]}.', false);
        }
        if (count($rows) > 50000) {
            throw new RunFailed('too_many_rows', 'The file has more than 50,000 rows.', false);
        }
        $headers = [];
        foreach ($rows as $row) {
            foreach (is_array($row) ? array_keys($row) : [] as $key) {
                $headers[$key] = true;
            }
        }
        $headers = array_map('strval', array_keys($headers));
        $fh = fopen($tmp, 'wb');
        fputcsv($fh, $headers);
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn ($h) => is_scalar($row[$h] ?? null) ? (string) $row[$h] : (is_bool($row[$h] ?? null) ? ($row[$h] ? 'true' : 'false') : ''), $headers));
        }
        fclose($fh);

        return $tmp;
    }

    private function validated(array $input): array
    {
        $data = validator($input, [
            'name' => 'required|string|max:150', 'kind' => 'required|in:'.implode(',', array_keys(self::KINDS)), 'format' => 'required|in:csv,json',
            'mapping' => 'sometimes|nullable|array|max:80', 'mapping.*' => 'nullable|string|max:120',
            'options' => 'sometimes|nullable|array', 'source' => 'required|in:upload,drop', 'interval_minutes' => 'sometimes|nullable|integer|min:5|max:10080',
        ])->validate();
        $data['mapping'] = array_filter($data['mapping'] ?? [], fn ($v) => $v !== null && $v !== '');
        foreach (array_keys($data['mapping']) as $field) {
            $known = $data['kind'] === 'attendance_punches' ? in_array($field, ImportRowValidator::FIELDS, true) : ImportColumns::isKnown((string) $field);
            if (! $known) {
                throw ValidationException::withMessages(["mapping.$field" => ['"'.$field.'" is not a supported field for this profile type.']]);
            }
        }
        $allowed = $data['kind'] === 'attendance_punches' ? [...AttendanceImportService::OPTIONS, 'accept_errors'] : ['date_format', 'skip_duplicates', 'allow_duplicates', 'default_country', 'default_timezone', 'default_worker_type', 'accept_errors'];
        $data['options'] = array_intersect_key($data['options'] ?? [], array_flip($allowed));
        if ($data['source'] === 'upload') {
            $data['interval_minutes'] = null;
        }

        return $data;
    }

    public function presentRun(JobRun $r): array
    {
        $profile = ImportProfile::query()->find($r->profile_id);

        return ['uuid' => $r->uuid, 'profile_uuid' => $profile?->uuid, 'profile_name' => $profile?->name, 'trigger' => $r->trigger, 'dry_run' => $r->dry_run, 'status' => $r->status, 'file_name' => $r->file_name,
            'attempts' => $r->attempts, 'total_rows' => $r->total_rows, 'valid_rows' => $r->valid_rows, 'error_rows' => $r->error_rows, 'imported_rows' => $r->imported_rows, 'error_code' => $r->error_code,
            'error_message' => $r->error_message, 'next_retry_at' => $r->next_retry_at?->toIso8601String(), 'started_at' => $r->started_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String()];
    }
}
