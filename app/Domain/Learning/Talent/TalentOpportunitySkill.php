<?php

namespace App\Domain\Learning\Talent;

use App\Domain\Tenancy\TenantRecord;

class TalentOpportunitySkill extends TenantRecord
{
    protected $table = 'talent_opportunity_skills';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['mandatory' => 'boolean'];
    }
}
