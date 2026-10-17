<?php

namespace App\Domain\Lifecycle\Continuity;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Responsibility extends TenantRecord
{
    use HasPublicId;

    protected $table = 'continuity_responsibilities';

    protected function casts(): array
    {
        return ['documented' => 'boolean', 'backup_trained' => 'boolean', 'last_reviewed_on' => 'date:Y-m-d'];
    }
}
