<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Template extends TenantRecord
{
    use HasPublicId;

    protected $table = 'lifecycle_templates';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
