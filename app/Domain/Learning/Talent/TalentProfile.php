<?php

namespace App\Domain\Learning\Talent;

use App\Domain\Tenancy\TenantRecord;

class TalentProfile extends TenantRecord
{
    protected $table = 'talent_profiles';

    protected function casts(): array
    {
        return ['discoverable' => 'boolean'];
    }
}
