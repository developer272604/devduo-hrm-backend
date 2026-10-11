<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Budget extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_budgets';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
