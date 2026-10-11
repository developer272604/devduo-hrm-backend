<?php

namespace App\Domain\Security\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class UserSession extends TenantRecord
{
    use HasPublicId;

    protected $table = 'user_sessions';

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
