<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Report extends TenantRecord
{
    use HasPublicId;

    protected $table = 'analytics_reports';

    protected function casts(): array
    {
        return ['spec' => 'array'];
    }
}
