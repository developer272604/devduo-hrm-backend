<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;

class SettlementItem extends TenantRecord
{
    protected $table = 'settlement_items';

    protected function casts(): array
    {
        return ['trace' => 'array', 'amount' => 'decimal:4'];
    }

    public function settlement()
    {
        return $this->belongsTo(SettlementRun::class, 'settlement_id');
    }
}
