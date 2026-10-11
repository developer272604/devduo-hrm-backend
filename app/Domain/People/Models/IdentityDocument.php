<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class IdentityDocument extends TenantRecord
{
    use HasPublicId;

    protected $table = 'identity_documents';

    protected $hidden = ['number_encrypted', 'number_hash'];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d', 'verified_at' => 'datetime'];
    }
}
