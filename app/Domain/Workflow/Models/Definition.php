<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Definition extends TenantRecord
{
    use HasPublicId;

    protected $table = 'workflow_definitions';

    protected function casts(): array
    {
        return ['draft' => 'array', 'revision' => 'integer'];
    }
}
