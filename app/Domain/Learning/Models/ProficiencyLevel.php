<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class ProficiencyLevel extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_proficiency_levels';
}
