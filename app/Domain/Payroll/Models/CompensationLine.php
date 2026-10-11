<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class CompensationLine extends TenantRecord
{
    use HasPublicId;
    protected $table = 'compensation_lines';
    protected function casts(): array { return ['amount' => 'encrypted', 'basis' => 'array']; }
    public function component() { return $this->belongsTo(SalaryComponent::class, 'component_id'); }
}
