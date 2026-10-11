<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollCalendar extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_calendars';

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function group()
    {
        return $this->belongsTo(PayrollGroup::class, 'payroll_group_id');
    }
}
