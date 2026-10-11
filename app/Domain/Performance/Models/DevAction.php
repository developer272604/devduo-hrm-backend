<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class DevAction extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_development_actions';

    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'completed_at' => 'datetime'];
    }
}
