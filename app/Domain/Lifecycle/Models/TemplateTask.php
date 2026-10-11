<?php

namespace App\Domain\Lifecycle\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class TemplateTask extends TenantRecord
{
    use HasPublicId;

    protected $table = 'lifecycle_template_tasks';

    protected function casts(): array
    {
        return ['mandatory' => 'boolean', 'requires_document' => 'boolean'];
    }
}
