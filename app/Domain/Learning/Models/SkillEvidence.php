<?php

namespace App\Domain\Learning\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class SkillEvidence extends TenantRecord
{
    use HasPublicId;

    protected $table = 'learning_skill_evidence';
}
