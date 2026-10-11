<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollRunEmployee extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_run_employees';

    protected function casts(): array
    {
        return ['employment_snapshot' => 'array'];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }

    public function employee()
    {
        return $this->belongsTo(\App\Domain\People\Models\Employee::class, 'employee_id');
    }

    public function currentResult()
    {
        return $this->belongsTo(PayrollCalculationResult::class, 'current_result_id');
    }
}
