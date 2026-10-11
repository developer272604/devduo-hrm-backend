<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Rule extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_rules';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
