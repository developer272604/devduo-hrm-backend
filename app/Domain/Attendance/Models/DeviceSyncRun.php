<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;

class DeviceSyncRun extends TenantRecord
{
    protected $table = 'device_sync_runs';

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function device()
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }
}
