<?php

namespace App\Domain\Recruitment\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class RequisitionVersion extends TenantRecord
{
    use HasPublicId;
    protected $table = 'recruitment_requisition_versions';
    protected function casts(): array { return ['before' => 'array', 'after' => 'array', 'changed_fields' => 'array', 'requires_approval' => 'boolean', 'decided_at' => 'datetime']; }
}
