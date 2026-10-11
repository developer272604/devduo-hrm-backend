<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

/** Scheduling record (see migration 2026_10_09_000001_create_attendance_domain). Date and time columns are plain strings (Y-m-d / H:i:s). */
class HolidayCalendar extends TenantRecord
{
    use HasPublicId;

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function holidays()
    {
        return $this->hasMany(Holiday::class, 'calendar_id')->orderBy('date');
    }
}
