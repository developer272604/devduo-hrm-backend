<?php

namespace App\Domain\Security\Compliance;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ComplianceControl extends TenantRecord
{
    use HasPublicId;

    protected $table = 'compliance_controls';

    protected function casts(): array
    {
        return ['next_due_on' => 'date:Y-m-d', 'active' => 'boolean'];
    }
}
