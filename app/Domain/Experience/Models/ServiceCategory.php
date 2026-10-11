<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class ServiceCategory extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_service_categories';
    protected function casts(): array { return ['active' => 'boolean']; }
}
