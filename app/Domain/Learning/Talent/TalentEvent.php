<?php

namespace App\Domain\Learning\Talent;

use App\Domain\Tenancy\TenantRecord;

class TalentEvent extends TenantRecord
{
    protected $table = 'talent_events';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
