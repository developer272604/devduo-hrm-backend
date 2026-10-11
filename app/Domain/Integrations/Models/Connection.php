<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Connection extends TenantRecord
{
    use HasPublicId;

    protected $table = 'integration_connections';
    protected $hidden = ['credential_ref'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'settings' => 'array', 'last_checked_at' => 'datetime'];
    }
}
