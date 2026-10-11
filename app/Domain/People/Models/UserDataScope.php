<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

/** Explicit per-user data-scope grant read by App\Domain\Access\DataScope. */
class UserDataScope extends TenantRecord
{
    protected $table = 'user_data_scopes';
}
