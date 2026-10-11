<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;

class LeavePayrollLine extends TenantRecord
{
    protected $table = 'leave_payroll_lines';

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }
}
