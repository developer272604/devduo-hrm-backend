<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Generation extends TenantRecord
{
    use HasPublicId;

    protected $table = 'workflow_generations';

    protected function casts(): array
    {
        return ['result' => 'array', 'edited_before_save' => 'boolean'];
    }
}
