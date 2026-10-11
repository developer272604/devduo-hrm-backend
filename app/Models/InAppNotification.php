<?php

namespace App\Models;

use App\Domain\Tenancy\TenantRecord;

class InAppNotification extends TenantRecord
{
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
