<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Competency extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_competencies';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
