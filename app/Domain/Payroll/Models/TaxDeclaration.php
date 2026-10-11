<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class TaxDeclaration extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_tax_declarations';

    protected function casts(): array
    {
        return ['data' => 'encrypted:array', 'reviewed_at' => 'datetime'];
    }

    public function proofs()
    {
        return $this->hasMany(TaxDeclarationProof::class, 'declaration_id');
    }
}
