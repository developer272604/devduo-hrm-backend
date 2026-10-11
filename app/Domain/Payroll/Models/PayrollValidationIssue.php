<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollValidationIssue extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_validation_issues';

    protected function casts(): array
    {
        return ['details' => 'array', 'action' => 'array', 'resolved_at' => 'datetime'];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
