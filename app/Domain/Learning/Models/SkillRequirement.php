<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class SkillRequirement extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_skill_requirements';

    protected function casts(): array
    {
        return ['mandatory' => 'boolean'];
    }
}
