<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ProfileChangeRequest extends TenantRecord
{
    use HasPublicId;

    protected $table = 'profile_change_requests';

    protected function casts(): array
    {
        return ['effective_date' => 'date:Y-m-d', 'decided_at' => 'datetime'];
    }
}
