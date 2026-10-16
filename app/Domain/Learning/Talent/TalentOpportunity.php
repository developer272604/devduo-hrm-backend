<?php

namespace App\Domain\Learning\Talent;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class TalentOpportunity extends TenantRecord
{
    use HasPublicId;

    protected $table = 'talent_opportunities';

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'closes_on' => 'date:Y-m-d'];
    }
}
