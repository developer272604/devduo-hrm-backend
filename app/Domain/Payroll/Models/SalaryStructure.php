<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class SalaryStructure extends TenantRecord
{
    use HasPublicId;

    protected $table = 'salary_structures';

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function components()
    {
        return $this->hasMany(SalaryStructureComponent::class, 'structure_id');
    }
}
