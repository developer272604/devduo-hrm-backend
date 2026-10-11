<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeaveAccrualItem extends TenantRecord
{
    protected $table = 'leave_accrual_items';

    protected function casts(): array
    {
        return ['calculation' => 'array'];
    }
}
