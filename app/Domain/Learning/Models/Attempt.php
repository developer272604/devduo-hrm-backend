<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Attempt extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_attempts';

    protected function casts(): array
    {
        return ['answers' => 'array', 'passed' => 'boolean'];
    }
}
