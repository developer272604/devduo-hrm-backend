<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class SalaryComponent extends TenantRecord
{
    use HasPublicId;
    protected $table = 'salary_components';
    protected function casts(): array { return ['payslip_visible' => 'boolean', 'attendance_dependent' => 'boolean', 'lop_dependent' => 'boolean', 'overtime_dependent' => 'boolean', 'active' => 'boolean', 'config' => 'array', 'published_at' => 'datetime']; }
}
