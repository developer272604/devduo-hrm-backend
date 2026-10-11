<?php

namespace App\Domain\Security\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class RetentionPolicy extends TenantRecord
{
    use HasPublicId;

    protected $table = 'retention_policies';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'retention_days' => 'integer'];
    }
}
