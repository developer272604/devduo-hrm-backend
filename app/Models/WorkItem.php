<?php

namespace App\Models;

use App\Domain\Tenancy\TenantRecord;

class WorkItem extends TenantRecord
{
    protected function casts(): array
    {
        return ['metadata' => 'array', 'due_date' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }
}
