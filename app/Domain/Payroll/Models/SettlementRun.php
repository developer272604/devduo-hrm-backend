<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class SettlementRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'settlement_runs';

    protected function casts(): array
    {
        return ['items_summary' => 'array', 'inputs' => 'array', 'net_payable' => 'decimal:4'];
    }

    public function items()
    {
        return $this->hasMany(SettlementItem::class, 'settlement_id');
    }

    public function employee()
    {
        return $this->belongsTo(\App\Domain\People\Models\Employee::class, 'employee_id');
    }
}
