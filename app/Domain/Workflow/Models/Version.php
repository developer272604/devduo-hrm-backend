<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Tenancy\TenantRecord;

class Version extends TenantRecord
{
    protected $table = 'workflow_versions';

    protected function casts(): array
    {
        return ['spec' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('This record is immutable.'));
        static::deleting(fn () => throw new \LogicException('This record is immutable.'));
    }
}
