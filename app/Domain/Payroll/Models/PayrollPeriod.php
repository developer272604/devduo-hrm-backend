<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollPeriod extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_periods';

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function group()
    {
        return $this->belongsTo(PayrollGroup::class, 'payroll_group_id');
    }

    public function calendar()
    {
        return $this->belongsTo(PayrollCalendar::class, 'calendar_id');
    }
}
