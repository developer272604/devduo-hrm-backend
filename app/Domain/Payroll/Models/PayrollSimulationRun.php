<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollSimulationRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_simulation_runs';

    protected function casts(): array
    {
        return ['params' => 'array', 'result' => 'encrypted:array'];
    }
}
