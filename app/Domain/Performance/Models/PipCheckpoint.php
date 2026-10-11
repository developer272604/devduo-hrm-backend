<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class PipCheckpoint extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_pip_checkpoints';

    protected function casts(): array
    {
        return ['expectation' => 'encrypted', 'note' => 'encrypted', 'due_on' => 'date:Y-m-d', 'recorded_at' => 'datetime', 'reminded_at' => 'datetime'];
    }
}
