<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Certification extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_certifications';

    protected function casts(): array
    {
        return ['reminder_days' => 'array', 'active' => 'boolean'];
    }
}
