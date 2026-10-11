<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class SkillCategory extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_skill_categories';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
