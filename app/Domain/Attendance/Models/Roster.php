<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

/** Scheduling record (see migration 2026_10_09_000001_create_attendance_domain). Date and time columns are plain strings (Y-m-d / H:i:s). */
class Roster extends TenantRecord
{
    use HasPublicId;

    protected function casts(): array
    {
        return ['published_at' => 'datetime', 'publication_version' => 'integer'];
    }

    public function entries()
    {
        return $this->hasMany(RosterEntry::class);
    }
}
