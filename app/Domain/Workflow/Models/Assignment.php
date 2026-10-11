<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Tenancy\TenantRecord;

class Assignment extends TenantRecord
{
    protected $table = 'workflow_assignments';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
