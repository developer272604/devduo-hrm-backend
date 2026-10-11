<?php

namespace App\Domain\Attendance\Support;

/**
 * Standard internal attendance statuses. These codes are the contract with payroll, leave and analytics and never
 * change meaning; tenants may only change the DISPLAY label (settings.attendance.status_labels).
 */
final class AttendanceStatus
{
    public const PRESENT = 'present';

    public const ABSENT = 'absent';

    public const HALF_DAY = 'half_day';

    public const WEEKLY_OFF = 'weekly_off';

    public const HOLIDAY = 'holiday';

    public const LEAVE = 'leave';                // placeholder: supplied by LeaveDataProvider (Phase 4)

    public const ON_DUTY = 'on_duty';

    public const REMOTE_WORK = 'remote_work';

    public const MISSING_PUNCH = 'missing_punch';

    public const INCOMPLETE = 'incomplete';

    public const NOT_SCHEDULED = 'not_scheduled';

    public const PENDING = 'pending';            // day not finished yet / awaiting punches

    public const ALL = [self::PRESENT, self::ABSENT, self::HALF_DAY, self::WEEKLY_OFF, self::HOLIDAY, self::LEAVE, self::ON_DUTY, self::REMOTE_WORK, self::MISSING_PUNCH, self::INCOMPLETE, self::NOT_SCHEDULED, self::PENDING];

    public const LABELS = [
        self::PRESENT => 'Present', self::ABSENT => 'Absent', self::HALF_DAY => 'Half day', self::WEEKLY_OFF => 'Weekly off', self::HOLIDAY => 'Holiday', self::LEAVE => 'On leave',
        self::ON_DUTY => 'On duty', self::REMOTE_WORK => 'Remote work', self::MISSING_PUNCH => 'Missing punch', self::INCOMPLETE => 'Incomplete', self::NOT_SCHEDULED => 'Not scheduled', self::PENDING => 'Pending',
    ];

    /** Statuses that count as a payable/paid day for readiness (payroll decides money; this only classifies). */
    public const COUNTS_AS_PRESENT = [self::PRESENT, self::ON_DUTY, self::REMOTE_WORK];

    public static function label(string $status, array $overrides = []): string
    {
        return $overrides[$status] ?? self::LABELS[$status] ?? $status;
    }
}
