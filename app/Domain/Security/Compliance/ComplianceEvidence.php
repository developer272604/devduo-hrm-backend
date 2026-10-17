<?php

namespace App\Domain\Security\Compliance;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ComplianceEvidence extends TenantRecord
{
    use HasPublicId;

    protected $table = 'compliance_evidence';

    protected function casts(): array
    {
        return ['covers_due_on' => 'date:Y-m-d', 'collected_on' => 'date:Y-m-d', 'reviewed_at' => 'datetime'];
    }
}
