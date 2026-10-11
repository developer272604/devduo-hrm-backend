<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class CompensationRevision extends TenantRecord
{
    use HasPublicId;

    protected $table = 'compensation_revisions';

    protected function casts(): array
    {
        return ['preview' => 'encrypted:array', 'decided_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(\App\Domain\People\Models\Employee::class, 'employee_id');
    }
}
