<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;

/** Scheduling record (see migration 2026_10_09_000001_create_attendance_domain). Date and time columns are plain strings (Y-m-d / H:i:s). */
class PayrollGroup extends TenantRecord
{
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function ($group) { $group->uuid ??= (string) \Illuminate\Support\Str::uuid(); });
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
