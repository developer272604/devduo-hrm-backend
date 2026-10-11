<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;

class DeviceUnmappedEvent extends TenantRecord
{
    protected $table = 'device_unmapped_events';

    protected function casts(): array
    {
        return ['raw' => 'array', 'occurred_at' => \App\Domain\Attendance\Capture\UtcInstant::class, 'resolved_at' => 'datetime'];
    }
}
