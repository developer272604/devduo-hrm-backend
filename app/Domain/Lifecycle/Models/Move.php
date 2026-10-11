<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Move extends TenantRecord
{
    use HasPublicId;

    protected $table = 'lifecycle_moves';

    protected function casts(): array
    {
        return ['changes' => 'array', 'effective_date' => 'date:Y-m-d', 'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }
}
