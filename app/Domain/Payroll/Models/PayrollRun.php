<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_runs';

    protected function casts(): array
    {
        return ['settings_snapshot' => 'array', 'control_totals' => 'array', 'progress' => 'array', 'calculated_at' => 'datetime', 'approved_at' => 'datetime', 'locked_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function group()
    {
        return $this->belongsTo(PayrollGroup::class, 'payroll_group_id');
    }

    public function period()
    {
        return $this->belongsTo(PayrollPeriod::class, 'period_id');
    }

    public function employees()
    {
        return $this->hasMany(PayrollRunEmployee::class, 'run_id');
    }
}
