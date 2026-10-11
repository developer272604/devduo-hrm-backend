<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class LeaveAccrualRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'leave_accrual_runs';

    protected function casts(): array
    {
        return ['scope' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(LeaveAccrualItem::class, 'run_id');
    }
}
