<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Feedback extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_feedback';

    protected function casts(): array
    {
        return ['anonymous' => 'boolean'];
    }
}
