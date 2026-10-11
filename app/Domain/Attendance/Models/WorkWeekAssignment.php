<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;

/** Scheduling record (see migration 2026_10_09_000001_create_attendance_domain). Date and time columns are plain strings (Y-m-d / H:i:s). */
class WorkWeekAssignment extends TenantRecord
{
    protected function casts(): array
    {
        return ['is_override' => 'boolean', 'priority' => 'integer'];
    }
}
