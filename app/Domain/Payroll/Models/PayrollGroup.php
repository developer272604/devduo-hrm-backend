<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollGroup extends TenantRecord
{
    use HasPublicId;
    protected $table = 'payroll_groups';
    protected function casts(): array { return ['active' => 'boolean', 'settings' => 'array']; }
}
