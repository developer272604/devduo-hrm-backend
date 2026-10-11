<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Tenancy\TenantRecord;

class Execution extends TenantRecord
{
    protected $table = 'workflow_executions';

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('This record is immutable.'));
        static::deleting(fn () => throw new \LogicException('This record is immutable.'));
    }
}
