<?php

namespace App\Domain\Attendance\Support;

/** Attendance exception types, default severities and neutral wording. Severity reflects operational urgency, never blame. */
final class ExceptionType
{
    public const LATE_ARRIVAL = 'late_arrival';

    public const EARLY_DEPARTURE = 'early_departure';

    public const MISSING_IN = 'missing_in';

    public const MISSING_OUT = 'missing_out';

    public const INSUFFICIENT_HOURS = 'insufficient_hours';

    public const UNEXPECTED_PUNCH = 'unexpected_punch';

    public const OUTSIDE_GEOFENCE = 'outside_geofence';

    public const UNSCHEDULED_WORK = 'unscheduled_work';

    public const EXCESSIVE_BREAK = 'excessive_break';

    public const OVERTIME = 'overtime';

    public const SHIFT_MISMATCH = 'shift_mismatch';

    public const NO_SHIFT = 'no_shift';

    public const NO_POLICY = 'no_policy';

    public const UNPAIRED_PUNCH = 'unpaired_punch';

    public const INTEGRITY_REVIEW = 'integrity_review';   // neutral wording: "Attendance integrity review required"

    public const REST_VIOLATION = 'rest_violation';

    /** type => [default severity, title] */
    public const CATALOGUE = [
        self::LATE_ARRIVAL => [Severity::WARNING, 'Late arrival'],
        self::EARLY_DEPARTURE => [Severity::WARNING, 'Early departure'],
        self::MISSING_IN => [Severity::ACTION_REQUIRED, 'Missing in punch'],
        self::MISSING_OUT => [Severity::ACTION_REQUIRED, 'Missing out punch'],
        self::INSUFFICIENT_HOURS => [Severity::WARNING, 'Insufficient hours'],
        self::UNEXPECTED_PUNCH => [Severity::INFO, 'Unexpected punch'],
        self::OUTSIDE_GEOFENCE => [Severity::WARNING, 'Punch outside the permitted location'],
        self::UNSCHEDULED_WORK => [Severity::INFO, 'Work on an unscheduled day'],
        self::EXCESSIVE_BREAK => [Severity::INFO, 'Break longer than allowed'],
        self::OVERTIME => [Severity::INFO, 'Overtime recorded'],
        self::SHIFT_MISMATCH => [Severity::WARNING, 'Punches do not match the scheduled shift'],
        self::NO_SHIFT => [Severity::ACTION_REQUIRED, 'No shift assigned'],
        self::NO_POLICY => [Severity::ACTION_REQUIRED, 'No attendance policy applies'],
        self::UNPAIRED_PUNCH => [Severity::ACTION_REQUIRED, 'Unpaired punch'],
        self::INTEGRITY_REVIEW => [Severity::WARNING, 'Attendance integrity review required'],
        self::REST_VIOLATION => [Severity::WARNING, 'Rest period below the configured minimum'],
    ];

    public static function severity(string $type): string
    {
        return self::CATALOGUE[$type][0] ?? Severity::INFO;
    }

    public static function title(string $type): string
    {
        return self::CATALOGUE[$type][1] ?? ucfirst(str_replace('_', ' ', $type));
    }
}
