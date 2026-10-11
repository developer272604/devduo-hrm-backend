<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveCyclePeriod extends TenantRecord
{
    protected $table = 'leave_cycle_periods';

    protected function casts(): array
    {
        return ['closed_at' => 'datetime'];
    }
}
