<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Dashboard extends TenantRecord
{
    use HasPublicId;

    protected $table = 'analytics_dashboards';

    protected function casts(): array
    {
        return ['widgets' => 'array'];
    }
}
