<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class Payslip extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payslips';

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'generated_at' => 'datetime', 'published_at' => 'datetime', 'first_viewed_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(\App\Domain\People\Models\Employee::class, 'employee_id');
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
