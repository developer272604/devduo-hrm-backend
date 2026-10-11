<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class TemplateVersion extends TenantRecord
{
    use HasPublicId;

    protected $table = 'lifecycle_template_versions';

    protected function casts(): array
    {
        return ['conditions' => 'array', 'published_at' => 'datetime'];
    }
}
