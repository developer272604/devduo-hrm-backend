<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

/** Scheduling record (see migration 2026_10_09_000001_create_attendance_domain). Date and time columns are plain strings (Y-m-d / H:i:s). */
class Shift extends TenantRecord
{
    use HasPublicId;

    protected function casts(): array
    {
        return ['crosses_midnight' => 'boolean', 'overtime_eligible' => 'boolean', 'active' => 'boolean', 'rules' => 'array'];
    }

    public function segments()
    {
        return $this->hasMany(ShiftSegment::class)->orderBy('sequence');
    }
}
