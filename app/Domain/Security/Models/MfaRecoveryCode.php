<?php

namespace App\Domain\Security\Models;

use App\Domain\Tenancy\TenantRecord;

class MfaRecoveryCode extends TenantRecord
{
    public const UPDATED_AT = null;

    protected $table = 'user_mfa_recovery_codes';

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }
}
