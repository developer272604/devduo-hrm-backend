<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveAdjustment extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_adjustments';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
