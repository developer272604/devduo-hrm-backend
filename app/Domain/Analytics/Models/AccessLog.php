<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\TenantRecord;

class AccessLog extends TenantRecord
{
    protected $table = 'analytics_access_logs';

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
