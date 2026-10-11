<?php

namespace App\Domain\Attendance\Capture\Import;

use App\Domain\Attendance\Capture\Devices\NormalizedPunch;
use App\Domain\Attendance\Capture\PunchRejected;
use App\Domain\Attendance\Capture\PunchService;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Models\AttendanceDevice;
use App\Domain\Attendance\Models\AttendanceEvent;
use App\Domain\Attendance\Models\AttendanceImport;
use App\Domain\Attendance\Models\EmployeeDeviceMapping;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Organization\OrganizationRecord;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Validates raw import rows (already keyed by the file's normalized header) against the saved mapping and options.
 *
 * Time rules (never guessed):
 *  - a value with an explicit offset (2026-10-07T09:00:00+05:30, ...Z) is an absolute instant;
 *  - otherwise the wall time is read in ONE zone: row timezone column > option "timezone" > device zone > location zone > employee zone;
 *  - a non-ISO value (e.g. 03/04/2026) needs the option "date_format" (PHP format, e.g. d/m/Y H:i); without it the row is an error;
 *  - wall times that occur twice (clocks go back) or not at all (clocks go forward) produce a warning and use TimeZones' rule
 *    (first occurrence / moved forward).
 */
class ImportRowValidator
{
    public const FIELDS = ['employee_number', 'work_email', 'device_employee_id', 'timestamp', 'date', 'time', 'direction', 'external_ref', 'device', 'location', 'timezone'];

    /** @var array<string,Employee|false|string> */
    private array $employeeCache = [];

    private array $deviceCache = [];

    private array $locationCache = [];

    private array $seen = [];

    private ?AttendanceDevice $fixedDevice = null;

    private array $mapping;

    private array $options;

    public function __construct(private AttendanceImport $import, private PunchService $punches)
    {
        $this->mapping = $import->mapping ?? [];
        $this->options = $import->options ?? [];
        if ($import->device_id) {
            $this->fixedDevice = AttendanceDevice::query()->find($import->device_id);
        }
    }

    /** Mapping problems that block validation altogether (friendly sentences). */
    public static function mappingProblems(array $mapping, string $kind): array
    {
        $problems = [];
        $ids = array_filter([$mapping['employee_number'] ?? null, $mapping['work_email'] ?? null, $mapping['device_employee_id'] ?? null]);
        if (! $ids) {
            $problems[] = 'Choose a column that identifies the employee: employee number, work email or device employee id.';
        }
        if ($kind === 'device_events' && empty($mapping['device_employee_id'])) {
            $problems[] = 'Device event files need the column holding the device employee id.';
        }
        if (empty($mapping['timestamp']) && (empty($mapping['date']) || empty($mapping['time']))) {
            $problems[] = 'Choose the timestamp column, or both a date column and a time column.';
        }

        return $problems;
    }

    /**
     * @param  array<int,array<string,?string>>  $rows  [rowNumber => raw]
     * @return array<int,array{status:string,normalized:?array,errors:array,warnings:array}>
     */
    public function validateChunk(array $rows): array
    {
        $out = [];
        foreach ($rows as $number => $raw) {
            $out[$number] = $this->validateOne($raw);
        }
        $this->markDuplicates($out);

        return $out;
    }

    private function value(array $raw, string $field): ?string
    {
        $column = $this->mapping[$field] ?? null;
        if (! $column) {
            return null;
        }
        $v = $raw[$column] ?? null;
        $v = is_string($v) ? trim($v) : $v;

        return $v === '' ? null : (is_scalar($v) ? (string) $v : null);
    }

    private function validateOne(array $raw): array
    {
        $errors = [];
        $warnings = [];
        $employee = null;
        $device = $this->fixedDevice;

        if (($deviceValue = $this->value($raw, 'device')) !== null) {
            $device = $this->deviceByValue($deviceValue);
            if (! $device) {
                $errors[] = 'No device matches "'.$deviceValue.'". Use the device identifier or name shown on the Devices page.';
            }
        }
        if ($this->import->kind === 'device_events' && ! $device && ! $errors) {
            $errors[] = 'Choose the device these events came from.';
        }

        $date = null;
        [$wall, $absolute, $parseError] = $this->parseWall($raw);
        if ($parseError) {
            $errors[] = $parseError;
        }
        $hintDate = $wall ? substr($wall, 0, 10) : TenantClock::today();

        // ---- employee -----------------------------------------------------------------------
        $number = $this->value($raw, 'employee_number');
        $email = $this->value($raw, 'work_email');
        $deviceEmployee = $this->value($raw, 'device_employee_id');
        if ($number !== null) {
            $employee = $this->employeeBy('number', $number);
            if (! $employee) {
                $errors[] = 'No employee has the number "'.$number.'".';
            }
        } elseif ($email !== null) {
            $employee = $this->employeeBy('email', mb_strtolower($email));
            if ($employee === 'ambiguous') {
                $errors[] = 'More than one employee uses the email "'.$email.'". Use the employee number instead.';
                $employee = null;
            } elseif (! $employee) {
                $errors[] = 'No employee has the work email "'.$email.'".';
            }
        } elseif ($deviceEmployee !== null) {
            if (! $device) {
                $errors[] = 'A device is needed to match the device employee id "'.$deviceEmployee.'".';
            } else {
                $employee = $this->employeeByMapping($device, $deviceEmployee, $hintDate);
                if (! $employee) {
                    $errors[] = 'Device employee id "'.$deviceEmployee.'" is not mapped to an employee on '.$hintDate.'.';
                }
            }
        } else {
            $errors[] = 'The row has no employee identifier.';
        }

        // ---- time ---------------------------------------------------------------------------
        $instant = null;
        $zone = null;
        $zoneSource = null;
        if ($wall && ! $parseError) {
            if ($absolute) {
                $instant = CarbonImmutable::parse($wall)->utc();
                $zone = $device && TimeZones::isValid($device->timezone) ? $device->timezone : ($employee?->timezone ?: 'UTC');
                $zoneSource = 'row_offset';
            } else {
                [$zone, $zoneSource, $zoneError] = $this->zoneFor($raw, $device, $employee);
                if ($zoneError) {
                    $errors[] = $zoneError;
                } else {
                    $state = self::wallState($wall, $zone);
                    if ($state === 'ambiguous') {
                        $warnings[] = 'This time happens twice on '.substr($wall, 0, 10).' in '.$zone.' because clocks go back. The first occurrence was used; check the row.';
                    } elseif ($state === 'gap') {
                        $warnings[] = 'This time does not exist on '.substr($wall, 0, 10).' in '.$zone.' because clocks go forward. It was moved forward by the gap; check the row.';
                    }
                    $instant = TimeZones::localToUtc(substr($wall, 0, 10), substr($wall, 11, 8), $zone);
                }
            }
            if ($instant && $instant->gt(CarbonImmutable::now('UTC')->addMinutes(5))) {
                $errors[] = 'The time is in the future.';
            }
        }

        $directionRaw = $this->value($raw, 'direction');
        $direction = $directionRaw === null ? 'UNKNOWN' : NormalizedPunch::direction($directionRaw);
        if ($directionRaw !== null && $direction === 'UNKNOWN' && strtoupper($directionRaw) !== 'UNKNOWN') {
            $warnings[] = 'The direction "'.$directionRaw.'" is not recognised; it was stored as UNKNOWN.';
        }
        $locationId = null;
        if (($locationValue = $this->value($raw, 'location')) !== null) {
            $locationId = $this->locationId($locationValue);
            if (! $locationId) {
                $warnings[] = 'The location "'.$locationValue.'" was not found and was ignored.';
            }
        }

        if ($employee instanceof Employee && $instant && ! $errors) {
            $localDate = TimeZones::localDate($instant, $zone);
            try {
                $this->punches->assertEmployed($employee, 'import', $localDate);
            } catch (PunchRejected $e) {
                $errors[] = $e->getMessage();
            }
            if (! $errors && app()->bound(LockGuard::class) && app(LockGuard::class)->isLocked($employee->id, $localDate)) {
                $errors[] = 'Attendance for '.$localDate.' is locked. Ask for an unlock before importing it.';
            }
            $date = $localDate;
        }

        $normalized = $errors ? null : [
            'employee_id' => $employee->id, 'employee_number' => $employee->employee_number, 'punched_at' => $instant->toIso8601String(), 'direction' => $direction,
            'external_ref' => ($ref = $this->value($raw, 'external_ref')) !== null ? substr($ref, 0, 120) : null, 'device_id' => $device?->id, 'location_id' => $locationId,
            'timezone' => $zone, 'timezone_source' => $zoneSource, 'local_date' => $date,
        ];

        return ['status' => $errors ? 'error' : ($warnings ? 'warning' : 'valid'), 'normalized' => $normalized, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** @param array<int,array> $results */
    private function markDuplicates(array &$results): void
    {
        $candidates = array_filter($results, fn ($r) => $r['normalized'] !== null);
        if (! $candidates) {
            return;
        }
        $employeeIds = array_unique(array_column(array_column($candidates, 'normalized'), 'employee_id'));
        $times = array_map(fn ($r) => CarbonImmutable::parse($r['normalized']['punched_at']), $candidates);
        $min = min(array_map(fn ($t) => $t->timestamp, $times));
        $max = max(array_map(fn ($t) => $t->timestamp, $times));
        $existing = [];
        $refs = [];
        AttendanceEvent::query()->whereIn('employee_id', $employeeIds)->whereNull('voided_at')
            ->whereBetween('punched_at', [CarbonImmutable::createFromTimestampUTC($min)->format('Y-m-d H:i:s'), CarbonImmutable::createFromTimestampUTC($max)->format('Y-m-d H:i:s')])
            ->get(['employee_id', 'punched_at', 'direction', 'device_id', 'external_ref'])->each(function ($e) use (&$existing, &$refs) {
                $existing[$e->employee_id.'|'.$e->punched_at->timestamp.'|'.$e->direction] = true;
                if ($e->external_ref !== null) {
                    $refs[$e->employee_id.'|'.($e->device_id ?? 0).'|'.$e->external_ref] = true;
                }
            });
        foreach ($results as $n => &$r) {
            $x = $r['normalized'];
            if ($x === null) {
                continue;
            }
            $time = CarbonImmutable::parse($x['punched_at'])->timestamp;
            $key = $x['employee_id'].'|'.$time.'|'.$x['direction'];
            $refKey = $x['external_ref'] !== null ? $x['employee_id'].'|'.($x['device_id'] ?? 0).'|'.$x['external_ref'] : null;
            if (isset($existing[$key]) || ($refKey && isset($refs[$refKey]))) {
                $r['status'] = 'duplicate';
                $r['warnings'][] = 'This punch already exists in attendance and will be skipped.';
            } elseif (isset($this->seen[$key]) || ($refKey && isset($this->seen[$refKey]))) {
                $r['status'] = 'duplicate';
                $r['warnings'][] = 'This punch appears earlier in the file and will be skipped.';
            }
            $this->seen[$key] = true;
            if ($refKey) {
                $this->seen[$refKey] = true;
            }
        }
    }

    /** @return array{0:?string,1:bool,2:?string} [wall "Y-m-d H:i:s" or offset-bearing ISO, absolute?, error] */
    private function parseWall(array $raw): array
    {
        $format = $this->options['date_format'] ?? null;
        $timestamp = $this->value($raw, 'timestamp');
        if ($timestamp !== null) {
            return $this->parseText($timestamp, $format);
        }
        $date = $this->value($raw, 'date');
        $time = $this->value($raw, 'time');
        if ($date === null || $time === null) {
            return [null, false, 'The row has no time. Both the date and the time are needed.'];
        }
        [$dateWall, , $error] = $this->parseText($date, $format, true);
        if ($error) {
            return [null, false, $error];
        }
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(am|pm)?$/i', $time, $m)) {
            return [null, false, 'The time "'.$time.'" could not be read. Use HH:MM or HH:MM:SS.'];
        }
        $h = (int) $m[1];
        if (! empty($m[4])) {
            $h = ($h % 12) + (strtolower($m[4]) === 'pm' ? 12 : 0);
        }
        if ($h > 23 || (int) $m[2] > 59 || (int) ($m[3] ?? 0) > 59) {
            return [null, false, 'The time "'.$time.'" is not a valid time of day.'];
        }

        return [sprintf('%s %02d:%02d:%02d', substr($dateWall, 0, 10), $h, $m[2], $m[3] ?? 0), false, null];
    }

    /** @return array{0:?string,1:bool,2:?string} */
    private function parseText(string $text, ?string $format, bool $dateOnly = false): array
    {
        if ($format) {
            $dt = DateTimeImmutable::createFromFormat('!'.$format, $text, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if (! $dt || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                return [null, false, 'The value "'.$text.'" does not match the date format "'.$format.'".'];
            }
            if (preg_match('/[eOPTp]/', $format)) {
                return [$dt->format('Y-m-d\TH:i:sP'), true, null];
            }

            return [$dt->format('Y-m-d H:i:s'), false, null];
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/i', $text, $m)) {
            if (! checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
                return [null, false, 'The date "'.$text.'" does not exist.'];
            }
            if (! isset($m[2]) || $m[2] === '') {
                return $dateOnly ? [$m[1].' 00:00:00', false, null] : [null, false, 'The value "'.$text.'" has a date but no time.'];
            }
            if ((int) $m[2] > 23 || (int) $m[3] > 59 || (int) ($m[4] ?? 0) > 59) {
                return [null, false, 'The time in "'.$text.'" is not valid.'];
            }
            if (! empty($m[5])) {
                return [CarbonImmutable::parse($text)->format('Y-m-d\TH:i:sP'), true, null];
            }

            return [sprintf('%s %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4] ?? 0), false, null];
        }

        return [null, false, 'Could not read the date "'.$text.'". Dates must be YYYY-MM-DD unless you choose the date format used in your file (for example d/m/Y H:i) in the import options.'];
    }

    /** @return array{0:?string,1:?string,2:?string} [zone, source, error] */
    private function zoneFor(array $raw, ?AttendanceDevice $device, ?Employee $employee): array
    {
        $rowZone = $this->value($raw, 'timezone');
        if ($rowZone !== null) {
            return TimeZones::isValid($rowZone) ? [$rowZone, 'row', null] : [null, null, 'The timezone "'.$rowZone.'" is not a valid IANA name such as Asia/Kolkata.'];
        }
        $option = $this->options['timezone'] ?? null;
        if ($option) {
            return TimeZones::isValid($option) ? [$option, 'file', null] : [null, null, 'The import timezone "'.$option.'" is not valid.'];
        }
        if ($device && TimeZones::isValid($device->timezone)) {
            return [$device->timezone, 'device', null];
        }
        if (($locationValue = $this->value($raw, 'location')) !== null && ($id = $this->locationId($locationValue))) {
            $zone = OrganizationRecord::forType('locations')->newQuery()->find($id)?->metadata['timezone'] ?? null;
            if (TimeZones::isValid($zone)) {
                return [$zone, 'location', null];
            }
        }
        if ($employee && TimeZones::isValid($employee->timezone)) {
            return [$employee->timezone, 'employee', null];
        }

        return [TenantClock::timezone(), 'tenant', null];
    }

    /** 'ok' | 'ambiguous' (occurs twice) | 'gap' (does not exist) for a wall time in a zone. */
    public static function wallState(string $wall, string $zone): string
    {
        $tz = new DateTimeZone($zone);
        $asUtc = (new DateTimeImmutable($wall, new DateTimeZone('UTC')))->getTimestamp();
        $before = $tz->getOffset((new DateTimeImmutable('@'.($asUtc - 86400))));
        $after = $tz->getOffset((new DateTimeImmutable('@'.($asUtc + 86400))));
        if ($before === $after) {
            return 'ok';
        }
        $c1 = $asUtc - $before;
        $c2 = $asUtc - $after;
        $valid1 = $tz->getOffset(new DateTimeImmutable('@'.$c1)) === $before;
        $valid2 = $tz->getOffset(new DateTimeImmutable('@'.$c2)) === $after;
        if ($valid1 && $valid2 && $c1 !== $c2) {
            return 'ambiguous';
        }

        return ! $valid1 && ! $valid2 ? 'gap' : 'ok';
    }

    private function employeeBy(string $kind, string $value): Employee|string|null
    {
        $key = $kind.'|'.$value;
        if (! array_key_exists($key, $this->employeeCache)) {
            $query = Employee::query();
            $kind === 'number' ? $query->whereRaw('LOWER(employee_number) = ?', [mb_strtolower($value)]) : $query->whereRaw('LOWER(work_email) = ?', [$value]);
            $found = $query->get();
            $this->employeeCache[$key] = $found->count() > 1 ? 'ambiguous' : ($found->first() ?? false);
        }
        $cached = $this->employeeCache[$key];

        return $cached === false ? null : $cached;
    }

    private function employeeByMapping(AttendanceDevice $device, string $deviceEmployeeId, string $date): ?Employee
    {
        $mapping = EmployeeDeviceMapping::query()->where('device_id', $device->id)->where('device_employee_id', $deviceEmployeeId)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first();

        return $mapping ? Employee::query()->find($mapping->employee_id) : null;
    }

    private function deviceByValue(string $value): ?AttendanceDevice
    {
        return $this->deviceCache[$value] ??= (AttendanceDevice::query()->where('device_identifier', $value)->first() ?? AttendanceDevice::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($value)])->first()) ?: false ?: null;
    }

    private function locationId(string $value): ?int
    {
        if (! array_key_exists($value, $this->locationCache)) {
            $q = OrganizationRecord::forType('locations')->newQuery();
            $this->locationCache[$value] = $q->where(fn ($w) => $w->whereRaw('LOWER(code) = ?', [mb_strtolower($value)])->orWhereRaw('LOWER(name) = ?', [mb_strtolower($value)]))->value('id');
        }

        return $this->locationCache[$value];
    }
}
