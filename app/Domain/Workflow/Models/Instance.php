<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Instance extends TenantRecord
{
    use HasPublicId;

    protected $table = 'workflow_instances';

    protected function casts(): array
    {
        return ['context' => 'array', 'due_at' => 'datetime', 'step_index' => 'integer', 'attempts' => 'integer'];
    }
}
