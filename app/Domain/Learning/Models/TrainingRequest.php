<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class TrainingRequest extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_requests';

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }
}
