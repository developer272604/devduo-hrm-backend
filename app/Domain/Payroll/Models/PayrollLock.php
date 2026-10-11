<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollLock extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_locks';

    protected function casts(): array
    {
        return ['post_lock_changes' => 'array', 'locked_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function period()
    {
        return $this->belongsTo(PayrollPeriod::class, 'period_id');
    }
}
