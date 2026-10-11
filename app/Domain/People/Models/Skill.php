<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

/** Tenant skill catalog entry. Deactivated, never deleted (people keep the skills they were assigned). */
class Skill extends TenantRecord
{
    protected $table = 'skills';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
