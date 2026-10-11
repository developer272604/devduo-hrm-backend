<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveRequestSegment extends TenantRecord
{
    protected $table = 'leave_request_segments';

    protected function casts(): array
    {
        return ['explanation' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
