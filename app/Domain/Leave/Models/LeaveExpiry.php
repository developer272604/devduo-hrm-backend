<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveExpiry extends TenantRecord
{
    protected $table = 'leave_expiries';

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }
}
