<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class ContractRenewal extends TenantRecord
{
    use HasPublicId;

    protected $table = 'lifecycle_contract_renewals';

    protected function casts(): array
    {
        return [
            'current_contract_end' => 'date:Y-m-d',
            'new_contract_end' => 'date:Y-m-d',
            'note' => 'encrypted',
            'decision_note' => 'encrypted',
            'decided_at' => 'datetime',
        ];
    }
}
