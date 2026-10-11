<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ApiClient extends TenantRecord
{
    use HasPublicId;

    protected $table = 'integration_api_clients';
    protected $hidden = ['key_hash', 'previous_key_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'ip_allowlist' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'rotated_at' => 'datetime', 'revoked_at' => 'datetime', 'previous_valid_until' => 'datetime'];
    }
}
