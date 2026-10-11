<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Assignment extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_assignments';

    protected function casts(): array
    {
        return ['assigned_on' => 'date:Y-m-d', 'due_on' => 'date:Y-m-d', 'completed_at' => 'datetime', 'escalated_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
}
