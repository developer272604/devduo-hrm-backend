<?php

namespace App\Models;

use App\Domain\Tenancy\TenantRecord;

class Role extends TenantRecord
{
    protected function casts(): array
    {
        return ['system' => 'boolean'];
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class);
    }
}
