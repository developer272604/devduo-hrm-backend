<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class LifecycleTask extends TenantRecord
{
    use HasPublicId;

    protected $table = 'lifecycle_tasks';

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'mandatory' => 'boolean', 'requires_document' => 'boolean', 'completed_at' => 'datetime', 'reminded_at' => 'datetime', 'escalated_at' => 'datetime'];
    }
}
