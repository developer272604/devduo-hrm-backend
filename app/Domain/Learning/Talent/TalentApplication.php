<?php

namespace App\Domain\Learning\Talent;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class TalentApplication extends TenantRecord
{
    use HasPublicId;

    protected $table = 'talent_applications';

    protected function casts(): array
    {
        return ['attested_skills' => 'array', 'placed_at' => 'datetime', 'closed_at' => 'datetime', 'release_decided_at' => 'datetime'];
    }
}
