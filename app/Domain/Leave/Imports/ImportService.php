<?php

namespace App\Domain\Leave\Imports;

use App\Domain\Attendance\Operations\Ops;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Accrual\AccountService;
use App\Domain\Leave\Accrual\RuleReader;
use App\Domain\Leave\Expiry\ExpiryService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveImport;
use App\Domain\Leave\Models\LeaveImportRow;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestAction;
use App\Domain\Leave\Models\LeaveRequestSegment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
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
 * CSV / XLSX imports of opening balances and historical leave: upload -> mapping -> validate (preview) -> commit (queued) -> errors -> retry.
 * Rows are kept with their raw values. Nothing ever overwrites the ledger: an opening balance is ONE ledger credit keyed
 * `opening:{employee}:{type}:{period start}` (re-importing the same value is a duplicate, a different value is an error that points at
 * adjustments), and historical requests are approved requests whose days are posted as consumption entries.
 */
final class ImportService
{
    public const DISK = 'leave_private';

    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ROWS = 20000;

    public const KINDS = ['opening_balance', 'historical_requests'];

    public const FIELDS = [
        'opening_balance' => ['employee_number', 'work_email', 'leave_type_code', 'units', 'as_of', 'expires_on', 'note'],
        'historical_requests' => ['employee_number', 'work_email', 'leave_type_code', 'start_date', 'end_date', 'units', 'reason', 'external_ref'],
    ];

    private const SYNONYMS = [
        'employee_number' => ['employee_number', 'employee_no', 'emp_no', 'emp_id', 'employee_id', 'staff_id', 'employee_code', 'emp_code'],
        'work_email' => ['work_email', 'email', 'email_address'],
        'leave_type_code' => ['leave_type_code', 'leave_type', 'type', 'type_code', 'leave_code', 'code'],
        'units' => ['units', 'days', 'balance', 'opening_balance', 'opening', 'quantity', 'leave_days'],
        'as_of' => ['as_of', 'as_of_date', 'effective_date', 'date', 'balance_date'],
        'expires_on' => ['expires_on', 'expiry', 'expiry_date', 'valid_until'],
        'note' => ['note', 'notes', 'remarks', 'comment'],
        'start_date' => ['start_date', 'from', 'from_date', 'start', 'leave_from'],
        'end_date' => ['end_date', 'to', 'to_date', 'end', 'leave_to'],
        'reason' => ['reason', 'remarks', 'note'],
        'external_ref' => ['external_ref', 'reference', 'ref', 'request_id', 'external_id'],
    ];

    public function __construct(private LedgerService $ledger, private AccountService $accounts, private ExpiryService $expiry, private DomainEvents $events) {}

    // ---- upload / mapping ---------------------------------------------------------------------------------

    public function upload(UploadedFile $file, string $kind, User $user): LeaveImport
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => ['Choose "opening_balance" or "historical_requests".']]);
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
        $path = 'leave-imports/'.$user->tenant_id.'/'.$uuid.'.'.$reader->format();
        Storage::disk(self::DISK)->put($path, file_get_contents($file->getPathname()));

        return LeaveImport::create(['uuid' => $uuid, 'user_id' => $user->id, 'kind' => $kind, 'filename' => mb_substr($file->getClientOriginalName(), 0, 250), 'path' => $path,
            'file_hash' => hash_file('sha256', $file->getPathname()), 'status' => 'uploaded', 'mapping' => $this->suggestMapping($kind, $reader->headers()), 'options' => [], 'total_rows' => $total]);
    }

    public function reader(LeaveImport $import): TabularReader
    {
        return TabularReader::open(Storage::disk(self::DISK)->path($import->path), $import->filename, ['max_rows' => self::MAX_ROWS, 'max_bytes' => self::MAX_BYTES]);
    }

    /** @return array{columns:array<int,array{key:string,label:string}>,sample:array,suggested_mapping:array,fields:array<int,string>} */
    public function preview(LeaveImport $import): array
    {
        $reader = $this->reader($import);
        $columns = [];
        foreach ($reader->headers() as $i => $key) {
            $columns[] = ['key' => $key, 'label' => $reader->rawHeaders()[$i] ?? $key];
        }

        return ['columns' => $columns, 'sample' => array_values($reader->sample(5)), 'suggested_mapping' => $this->suggestMapping($import->kind, $reader->headers()), 'fields' => self::FIELDS[$import->kind]];
    }

    public function suggestMapping(string $kind, array $headers): array
    {
        $mapping = [];
        foreach (self::FIELDS[$kind] as $field) {
            foreach (self::SYNONYMS[$field] ?? [] as $name) {
                if (in_array($name, $headers, true) && ! in_array($name, $mapping, true)) {
                    $mapping[$field] = $name;
                    break;
                }
            }
        }

        return $mapping;
    }

    public function saveMapping(LeaveImport $import, array $mapping, array $options): LeaveImport
    {
        if (! in_array($import->status, ['uploaded', 'mapped', 'validated', 'failed', 'completed_with_errors'], true)) {
            throw new HttpException(409, 'This import is already being processed.');
        }
        $headers = $this->reader($import)->headers();
        $clean = [];
        foreach ($mapping as $field => $column) {
            if (! in_array($field, self::FIELDS[$import->kind], true) || $column === null || $column === '') {
                continue;
            }
            if (! in_array($column, $headers, true)) {
                throw ValidationException::withMessages(["mapping.$field" => ['The column "'.$column.'" is not in the file.']]);
            }
            $clean[$field] = $column;
        }
        if ($problems = $this->mappingProblems($import->kind, $clean)) {
            throw ValidationException::withMessages(['mapping' => $problems]);
        }
        $options = array_filter(array_intersect_key($options, array_flip(['date_format'])), fn ($v) => $v !== null && $v !== '');
        if (isset($options['date_format']) && ! in_array($options['date_format'], ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y'], true)) {
            throw ValidationException::withMessages(['options.date_format' => ['Use Y-m-d, d/m/Y, m/d/Y or d-m-Y.']]);
        }
        $import->forceFill(['mapping' => $clean, 'options' => $options, 'status' => 'mapped'])->save();

        return $import;
    }

    /** @return array<int,string> */
    public function mappingProblems(string $kind, array $mapping): array
    {
        $p = [];
        if (! isset($mapping['employee_number']) && ! isset($mapping['work_email'])) {
            $p[] = 'Map either the employee number or the work email column.';
        }
        $required = $kind === 'opening_balance' ? ['leave_type_code', 'units'] : ['leave_type_code', 'units', 'start_date', 'end_date'];
        foreach ($required as $f) {
            if (! isset($mapping[$f])) {
                $p[] = 'Map the '.str_replace('_', ' ', $f).' column.';
            }
        }

        return $p;
    }

    // ---- validate -----------------------------------------------------------------------------------------

    public function validate(LeaveImport $import): LeaveImport
    {
        if (! $import->mapping || $this->mappingProblems($import->kind, $import->mapping)) {
            throw ValidationException::withMessages(['mapping' => ['Map the file columns first.']]);
        }
        LeaveImportRow::query()->where('import_id', $import->id)->where('status', '!=', 'imported')->delete();
        $import->forceFill(['status' => 'validating'])->save();
        $user = User::query()->find($import->user_id);
        $ctx = $this->context($import, $user);
        $seen = LeaveImportRow::query()->where('import_id', $import->id)->where('status', 'imported')->pluck('idempotency_key')->filter()->flip()->all();
        foreach ($this->reader($import)->chunks(500) as $rows) {
            $insert = [];
            foreach ($rows as $number => $row) {
                $r = $this->validateRow($import, $ctx, $row, $seen);
                $insert[] = ['tenant_id' => $import->tenant_id, 'import_id' => $import->id, 'row_number' => $number, 'raw' => json_encode($row, JSON_UNESCAPED_UNICODE), 'normalized' => $r['normalized'] ? json_encode($r['normalized']) : null,
                    'status' => $r['status'], 'errors' => $r['errors'] ? json_encode($r['errors']) : null, 'warnings' => $r['warnings'] ? json_encode($r['warnings']) : null, 'idempotency_key' => $r['key'], 'created_at' => now(), 'updated_at' => now()];
            }
            // Imported rows survive a re-validate, so skip their numbers.
            $existing = LeaveImportRow::query()->where('import_id', $import->id)->whereIn('row_number', array_keys($rows))->pluck('row_number')->all();
            $insert = array_values(array_filter($insert, fn ($i) => ! in_array($i['row_number'], $existing, true)));
            $insert && DB::table('leave_import_rows')->insert($insert);
        }
        $this->recount($import);
        $import->forceFill(['status' => 'validated'])->save();

        return $import;
    }

    /** @return array<string,mixed> */
    private function context(LeaveImport $import, ?User $user): array
    {
        return ['reach' => $user ? array_flip(Ops::employeeIds($user, 'leave.import', false)) : [], 'types' => LeaveType::query()->get()->keyBy(fn ($t) => mb_strtolower($t->code)), 'dateFormat' => $import->options['date_format'] ?? 'Y-m-d', 'file' => []];
    }

    /** @return array{status:string,normalized:?array,errors:array,warnings:array,key:?string} */
    private function validateRow(LeaveImport $import, array &$ctx, array $row, array &$seen): array
    {
        $m = $import->mapping;
        $get = fn (string $f) => isset($m[$f]) ? ($row[$m[$f]] ?? null) : null;
        $errors = [];
        $warnings = [];
        $employee = $this->employee($get('employee_number'), $get('work_email'));
        if (! $employee) {
            $errors[] = 'Employee not found. Check the employee number or work email.';
        } elseif (! isset($ctx['reach'][$employee->id])) {
            $errors[] = 'Employee not found. Check the employee number or work email.';
            $employee = null;
        }
        $type = $ctx['types'][mb_strtolower((string) $get('leave_type_code'))] ?? null;
        if (! $type) {
            $errors[] = 'Unknown leave type code "'.$get('leave_type_code').'".';
        } elseif (! $type->active) {
            $errors[] = 'Leave type "'.$type->code.'" is inactive.';
        }
        $units = null;
        try {
            $units = Units::strict(str_replace(',', '', trim((string) $get('units'))));
            if (Units::isNegative($units) || Units::isZero($units)) {
                $errors[] = 'Units must be greater than zero.';
            }
        } catch (\InvalidArgumentException $e) {
            $errors[] = 'Units: '.$e->getMessage();
        }
        $n = ['employee_id' => $employee?->id, 'leave_type_id' => $type?->id, 'units' => $units];
        $key = null;
        if ($import->kind === 'opening_balance') {
            $asOf = $this->date($get('as_of'), $ctx['dateFormat']) ?? TenantClock::today();
            if ($get('as_of') && ! $this->date($get('as_of'), $ctx['dateFormat'])) {
                $errors[] = 'The as-of date is not a valid date.';
            }
            $expires = $get('expires_on') ? $this->date($get('expires_on'), $ctx['dateFormat']) : null;
            if ($get('expires_on') && ! $expires) {
                $errors[] = 'The expiry date is not a valid date.';
            } elseif ($expires && $expires < $asOf) {
                $errors[] = 'The expiry date is before the as-of date.';
            }
            $n += ['effective_date' => $asOf, 'expires_on' => $expires, 'note' => $get('note')];
            if ($employee && $type) {
                $period = $this->accounts->periodFor($employee->id, $type->id, $asOf);
                $n['period_start'] = $period['start'];
                $key = 'opening:'.$employee->id.':'.$type->id.':'.$period['start'];
                if (isset($seen[$key]) && ! $errors) {
                    $errors[] = 'This employee already has an opening balance row for the same leave type and period in this file.';
                } elseif (($existing = $this->ledger->find($key)) && ! $errors) {
                    if (Units::cmp($existing->getRawOriginal('units'), $units) === 0) {
                        $warnings[] = 'This opening balance is already in the ledger and will be skipped.';
                        $n['already_posted'] = true;
                    } else {
                        $errors[] = 'An opening balance of '.Units::normalize($existing->getRawOriginal('units')).' already exists for this period. Use a balance adjustment to change it.';
                    }
                }
                $seen[$key] = true;
            }
        } else {
            $start = $this->date($get('start_date'), $ctx['dateFormat']);
            $end = $this->date($get('end_date'), $ctx['dateFormat']);
            if (! $start || ! $end) {
                $errors[] = 'The start and end dates must be valid dates.';
            } elseif ($end < $start) {
                $errors[] = 'The end date is before the start date.';
            } elseif (CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) > 366) {
                $errors[] = 'A single historical request cannot span more than a year.';
            } elseif ($units && ! $errors && Units::cmp($units, (string) (CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) + 1)) > 0) {
                $errors[] = 'Units exceed the number of calendar days in the range.';
            }
            $n += ['start_date' => $start, 'end_date' => $end, 'reason' => $get('reason'), 'external_ref' => $get('external_ref')];
            if ($employee && $type && $start && $end && ! $errors) {
                $key = 'hist:'.$employee->id.':'.$type->id.':'.$start.':'.$end;
                if (isset($seen[$key])) {
                    $errors[] = 'This request appears twice in the file.';
                } elseif (LeaveRequest::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('start_date', $start)->where('end_date', $end)
                    ->whereNotIn('status', ['rejected', 'withdrawn', 'cancelled'])->exists()) {
                    $warnings[] = 'A request for these dates already exists and will be skipped.';
                    $n['already_posted'] = true;
                } elseif (LeaveRequest::query()->where('employee_id', $employee->id)->where('start_date', '<=', $end)->where('end_date', '>=', $start)->whereIn('status', ['approved', 'pending', 'partially_cancelled', 'modified'])->exists()) {
                    $warnings[] = 'These dates overlap an existing leave request.';
                }
                $seen[$key] = true;
            }
        }
        $status = $errors ? 'error' : (! empty($n['already_posted']) ? 'duplicate' : ($warnings ? 'warning' : 'valid'));

        return ['status' => $status, 'normalized' => $errors ? null : $n, 'errors' => $errors, 'warnings' => $warnings, 'key' => $key ? mb_substr($key, 0, 120) : null];
    }

    private function employee(?string $number, ?string $email): ?Employee
    {
        if ($number !== null && $number !== '') {
            return Employee::query()->where('employee_number', trim($number))->first();
        }
        if ($email !== null && $email !== '') {
            $matches = Employee::query()->whereRaw('LOWER(work_email) = ?', [mb_strtolower(trim($email))])->limit(2)->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return null;
    }

    private function date(?string $value, string $format): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        foreach (array_unique([$format, 'Y-m-d']) as $f) {
            if (CarbonImmutable::hasFormat($value, $f)) {
                try {
                    $d = CarbonImmutable::createFromFormat('!'.$f, $value);

                    return $d && $d->format($f) === $value ? $d->toDateString() : null;
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        return null;
    }

    public function recount(LeaveImport $import): void
    {
        $counts = LeaveImportRow::query()->where('import_id', $import->id)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $import->forceFill(['total_rows' => (int) $counts->sum(), 'valid_rows' => (int) ($counts['valid'] ?? 0), 'warning_rows' => (int) ($counts['warning'] ?? 0), 'error_rows' => (int) ($counts['error'] ?? 0),
            'imported_rows' => (int) ($counts['imported'] ?? 0), 'duplicate_rows' => (int) ($counts['duplicate'] ?? 0)])->save();
    }

    // ---- commit -------------------------------------------------------------------------------------------

    /** Runs inside the queued job (tenant context set). Idempotent: imported rows are skipped, ledger keys are per row. */
    public function commit(LeaveImport $import, ?int $requestedBy = null): LeaveImport
    {
        if (! in_array($import->status, ['queued', 'importing', 'validated', 'completed_with_errors'], true)) {
            return $import;
        }
        $import->forceFill(['status' => 'importing', 'started_at' => $import->started_at ?? now()])->save();
        $user = User::query()->find($requestedBy ?? $import->user_id);
        $touched = [];
        try {
            LeaveImportRow::query()->where('import_id', $import->id)->whereIn('status', ['valid', 'warning'])->orderBy('id')->chunkById(200, function ($rows) use ($import, $user, &$touched) {
                foreach ($rows as $row) {
                    try {
                        DB::transaction(fn () => $import->kind === 'opening_balance' ? $this->commitOpening($import, $row, $user) : $this->commitHistorical($import, $row, $user, $touched));
                    } catch (HttpException|ValidationException $e) {
                        $row->forceFill(['status' => 'error', 'errors' => [$e instanceof ValidationException ? collect($e->errors())->flatten()->implode(' ') : ($e->getMessage() ?: 'The ledger refused this row.')]])->save();
                    } catch (\Throwable $e) {
                        report($e);
                        $row->forceFill(['status' => 'error', 'errors' => ['This row could not be imported. Try again; if it keeps failing, contact support.']])->save();
                    }
                }
            });
        } catch (\Throwable $e) {
            Log::error('leave.import_failed', ['import' => $import->uuid, 'tenant_id' => $import->tenant_id, 'error' => $e->getMessage()]);
            $import->forceFill(['status' => 'failed', 'finished_at' => now()])->save();
            $this->recount($import);

            throw $e;
        }
        $this->expiry->sync();
        foreach ($touched as $employeeId => $dates) {
            $this->events->record(LeaveEvents::COVERAGE_CHANGED, ['employee' => $employeeId, 'summary' => 'Historical leave imported', 'actor_id' => $user?->id, 'payload' => ['employee_id' => $employeeId, 'dates' => array_values(array_unique($dates))]]);
        }
        $this->recount($import);
        $import->forceFill(['status' => $import->error_rows > 0 ? 'completed_with_errors' : 'completed', 'finished_at' => now()])->save();
        Audit::record('leave_import_committed', 'leave', 'leave_import:'.$import->uuid, ['kind' => $import->kind, 'file' => $import->filename, 'imported' => $import->imported_rows, 'duplicates' => $import->duplicate_rows, 'errors' => $import->error_rows]);

        return $import;
    }

    private function commitOpening(LeaveImport $import, LeaveImportRow $row, ?User $user): void
    {
        $n = $row->normalized;
        $account = $this->accounts->ensure($n['employee_id'], $n['leave_type_id'], $n['effective_date']);
        $result = $this->ledger->post(new LedgerPosting($account->id, 'opening', 'opening', $n['units'], $n['effective_date'], 'opening:'.$n['employee_id'].':'.$n['leave_type_id'].':'.$n['period_start'],
            expiresOn: $n['expires_on'] ?? null, importRowId: $row->id, referenceType: 'leave_import', referenceId: $import->uuid, reason: mb_substr('Opening balance import'.(($n['note'] ?? null) ? ': '.$n['note'] : ''), 0, 500),
            meta: ['import' => $import->uuid, 'row' => $row->row_number, 'original' => $row->raw], createdBy: $user?->id));
        $row->forceFill(['status' => $result->duplicate ? 'duplicate' : 'imported', 'ledger_entry_id' => $result->entry->id])->save();
    }

    private function commitHistorical(LeaveImport $import, LeaveImportRow $row, ?User $user, array &$touched): void
    {
        $n = $row->normalized;
        $key = 'import:'.$import->uuid.':'.$row->row_number;
        if ($existing = LeaveRequest::query()->where('idempotency_key', $key)->first()) {
            $row->forceFill(['status' => 'duplicate', 'request_id' => $existing->id])->save();

            return;
        }
        $today = TenantClock::today();
        $remaining = $n['units'];
        $days = [];
        for ($d = CarbonImmutable::parse($n['start_date']); $d->lte(CarbonImmutable::parse($n['end_date'])); $d = $d->addDay()) {
            $take = Units::min('1', $remaining);
            $remaining = Units::sub($remaining, $take);
            $days[] = ['date' => $d->toDateString(), 'units' => $take];
        }
        $request = LeaveRequest::create(['employee_id' => $n['employee_id'], 'leave_type_id' => $n['leave_type_id'], 'status' => 'approved', 'start_date' => $n['start_date'], 'end_date' => $n['end_date'], 'unit' => 'day',
            'portion' => 'full_day', 'requested_units' => $n['units'], 'charged_units' => $n['units'], 'reason' => $n['reason'] ?? null, 'requested_by' => $user?->id, 'idempotency_key' => $key, 'confidence' => 'confirmed',
            'explanation' => ['source' => 'historical_import', 'import' => $import->uuid, 'row' => $row->row_number, 'external_ref' => $n['external_ref'] ?? null], 'submitted_at' => now(), 'decided_at' => now(), 'decided_by' => $user?->id,
            'total_steps' => 1, 'current_step' => 1]);
        $firstEntry = null;
        foreach ($days as $day) {
            $charged = ! Units::isZero($day['units']);
            $account = $charged ? $this->accounts->ensure($n['employee_id'], $n['leave_type_id'], $day['date']) : null;
            $portion = Units::cmp($day['units'], '1') === 0 ? 'full_day' : (Units::cmp($day['units'], '0.5') === 0 ? 'first_half' : 'hours');
            $segment = LeaveRequestSegment::create(['request_id' => $request->id, 'employee_id' => $n['employee_id'], 'leave_type_id' => $n['leave_type_id'], 'account_id' => $account?->id, 'leave_date' => $day['date'],
                'portion' => $charged ? $portion : 'full_day', 'minutes' => 0, 'units' => $day['units'], 'pay_class' => 'paid', 'day_kind' => $charged ? 'workday' : 'weekly_off', 'charged' => $charged, 'status' => 'active',
                'explanation' => ['source' => 'historical_import', 'note' => $charged ? 'Imported day' : 'Day inside the imported range that was not charged']]);
            if ($charged) {
                $entry = $this->ledger->post(new LedgerPosting($account->id, 'consumption', $day['date'] > $today ? 'scheduled' : 'consumed', Units::neg($day['units']), $day['date'], $key.':'.$day['date'], requestId: $request->id, segmentId: $segment->id,
                    importRowId: $row->id, referenceType: 'leave_import', referenceId: $import->uuid, reason: 'Historical leave import', meta: ['import' => $import->uuid, 'row' => $row->row_number], createdBy: $user?->id))->entry;
                $segment->forceFill(['ledger_entry_id' => $entry->id])->save();
                $firstEntry ??= $entry;
            }
            $touched[$n['employee_id']][] = $day['date'];
        }
        LeaveRequestAction::create(['request_id' => $request->id, 'actor_user_id' => $user?->id, 'action' => 'system', 'from_status' => null, 'to_status' => 'approved', 'comment' => 'Imported historical leave', 'after' => ['import' => $import->uuid, 'row' => $row->row_number]]);
        $row->forceFill(['status' => 'imported', 'request_id' => $request->id, 'ledger_entry_id' => $firstEntry?->id])->save();
    }

    /** Re-validates rows that failed (employees or mappings may have been fixed), so they can be committed again. */
    public function revalidateFailed(LeaveImport $import): void
    {
        $user = User::query()->find($import->user_id);
        $ctx = $this->context($import, $user);
        $seen = [];
        LeaveImportRow::query()->where('import_id', $import->id)->where('status', 'error')->orderBy('id')->chunkById(500, function ($rows) use ($import, &$ctx, &$seen) {
            foreach ($rows as $row) {
                $r = $this->validateRow($import, $ctx, $row->raw, $seen);
                $row->forceFill(['status' => $r['status'], 'normalized' => $r['normalized'], 'errors' => $r['errors'] ?: null, 'warnings' => $r['warnings'] ?: null, 'idempotency_key' => $r['key']])->save();
            }
        });
        $this->recount($import);
    }

    // ---- files / presentation -----------------------------------------------------------------------------

    /** @return array{0:string,1:string} [path, filename] */
    public function errorsFile(LeaveImport $import): array
    {
        $first = LeaveImportRow::query()->where('import_id', $import->id)->where('status', 'error')->orderBy('row_number')->first();
        $columns = array_keys($first?->raw ?? []);
        $path = tempnam(sys_get_temp_dir(), 'lvimperr');
        $writer = TabularWriter::open('csv', $path, array_merge(['row_number', 'problems'], $columns ?: ['values']));
        LeaveImportRow::query()->where('import_id', $import->id)->where('status', 'error')->orderBy('row_number')->chunk(500, function ($rows) use ($writer, $columns) {
            foreach ($rows as $row) {
                $writer->write(array_merge([$row->row_number, implode(' ', $row->errors ?? [])], array_map(fn ($c) => $row->raw[$c] ?? null, $columns)));
            }
        });
        $writer->close();

        return [$path, 'leave-import-errors-'.substr($import->uuid, 0, 8).'.csv'];
    }

    public static function templateColumns(string $kind): array
    {
        return $kind === 'historical_requests' ? ['employee_number', 'leave_type_code', 'start_date', 'end_date', 'units', 'reason', 'external_ref'] : ['employee_number', 'leave_type_code', 'units', 'as_of', 'expires_on', 'note'];
    }

    public static function templateExample(string $kind): array
    {
        return $kind === 'historical_requests'
            ? [['EMP-00001', 'AL', '2026-03-02', '2026-03-04', '3', 'Family trip', 'LEG-1001']]
            : [['EMP-00001', 'AL', '12.5', '2026-01-01', '', 'Migrated from previous system']];
    }

    public function template(string $kind, string $format)
    {
        return Template::download(self::templateColumns($kind), self::templateExample($kind), $format, 'leave-'.str_replace('_', '-', $kind).'-template');
    }

    /** @return array<string,mixed> */
    public function present(LeaveImport $import): array
    {
        return ['id' => $import->uuid, 'kind' => $import->kind, 'filename' => $import->filename, 'status' => $import->status, 'mapping' => $import->mapping, 'options' => $import->options,
            'counts' => ['total' => $import->total_rows, 'valid' => $import->valid_rows, 'warnings' => $import->warning_rows, 'errors' => $import->error_rows, 'imported' => $import->imported_rows, 'duplicates' => $import->duplicate_rows],
            'progress_percent' => $import->total_rows ? (int) round(100 * ($import->imported_rows + $import->duplicate_rows + $import->error_rows) / max(1, $import->total_rows)) : 0,
            'started_at' => $import->started_at?->toIso8601String(), 'finished_at' => $import->finished_at?->toIso8601String(), 'created_at' => $import->created_at?->toIso8601String(), 'message' => $this->message($import)];
    }

    private function message(LeaveImport $import): ?string
    {
        return match (true) {
            $import->status === 'validated' && $import->error_rows > 0 => "We couldn't accept {$import->error_rows} row(s). Download the error file to fix them, or import the rest.",
            $import->status === 'completed_with_errors' => "Imported {$import->imported_rows} row(s). {$import->error_rows} row(s) failed: download the error file, correct them and upload again.",
            $import->status === 'completed' => "Imported {$import->imported_rows} row(s); {$import->duplicate_rows} duplicate(s) were skipped.",
            $import->status === 'failed' => 'The import stopped unexpectedly. Your data is safe; use Retry to continue.',
            default => null,
        };
    }
}
