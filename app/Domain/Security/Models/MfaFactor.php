<?php

namespace App\Domain\Security\Models;

use App\Domain\Tenancy\TenantRecord;

class MfaFactor extends TenantRecord
{
    protected $table = 'user_mfa_factors';

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'last_used_step' => 'integer'];
    }
}
