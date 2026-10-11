<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveConflict extends TenantRecord
{
    protected $table = 'leave_conflicts';

    protected function casts(): array
    {
        return ['detail' => 'array', 'resolved_at' => 'datetime'];
    }
}
