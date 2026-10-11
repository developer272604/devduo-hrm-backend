<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

/** Configurable employee tag. required_permission, when set, restricts who may assign (and see) it. */
class Tag extends TenantRecord
{
    protected $table = 'tags';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
