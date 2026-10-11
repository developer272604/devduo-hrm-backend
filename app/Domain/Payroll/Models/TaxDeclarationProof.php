<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class TaxDeclarationProof extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_tax_declaration_proofs';

    protected function casts(): array
    {
        return ['declared_amount' => 'encrypted', 'accepted_amount' => 'encrypted'];
    }

    public function declaration()
    {
        return $this->belongsTo(TaxDeclaration::class, 'declaration_id');
    }
}
