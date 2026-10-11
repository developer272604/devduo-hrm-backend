<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class LifecycleCase extends TenantRecord
{
    use HasPublicId;

    protected $table = 'lifecycle_cases';

    protected function casts(): array
    {
        return ['anchor_date' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }
}
