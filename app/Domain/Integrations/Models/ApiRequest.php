<?php

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ApiRequest extends TenantRecord
{

    protected $table = 'integration_api_requests';
    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
