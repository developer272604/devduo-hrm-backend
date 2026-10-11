<?php

namespace App\Domain\Security\Models;

use App\Domain\Tenancy\TenantRecord;

class PasswordHistory extends TenantRecord
{
    public const UPDATED_AT = null;

    protected $table = 'password_histories';
}
