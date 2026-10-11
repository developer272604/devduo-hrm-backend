<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Attendance\Support\TimeZones;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Parses a wall-clock value entered in the employee's / shift's zone ("09:30", "2026-10-06 09:30", "2026-10-06T09:30") into a UTC instant. */
final class LocalInstant
{
    /** @param string|null $dayOffsetDate the attendance date a bare "HH:mm" belongs to */
    public static function toUtc(?string $value, string $date, string $zone, string $field = 'time'): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return TimeZones::localToUtc($date, sprintf('%02d:%02d', $m[1], $m[2]), $zone);
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m) && (int) $m[2] < 24 && (int) $m[3] < 60 && checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
            return TimeZones::localToUtc($m[1], sprintf('%02d:%02d', $m[2], $m[3]), $zone);
        }
        throw ValidationException::withMessages([$field => 'Enter the time as HH:MM (for example 09:30) or as a date and time (2026-10-06 09:30).']);
    }

    public static function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }

    public static function utcString(CarbonImmutable $instant): string
    {
        return $instant->utc()->format('Y-m-d H:i:s');
    }
}
