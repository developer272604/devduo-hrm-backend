<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class SalaryStructureComponent extends TenantRecord
{
    use HasPublicId;

    protected $table = 'salary_structure_components';

    protected function casts(): array
    {
        return ['overrides' => 'array', 'required' => 'boolean'];
    }

    public function structure()
    {
        return $this->belongsTo(SalaryStructure::class, 'structure_id');
    }
}
