<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class WorkAuthorization extends TenantRecord
{
    use HasPublicId;

    protected $table = 'work_authorizations';

    public const STATUSES = ['active', 'pending', 'expired', 'revoked'];

    protected $hidden = ['number_encrypted'];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d', 'verified_at' => 'datetime'];
    }
}
