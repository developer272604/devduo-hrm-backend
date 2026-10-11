<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Skill extends TenantRecord
{
    use HasPublicId;

    protected $table = 'skills';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
