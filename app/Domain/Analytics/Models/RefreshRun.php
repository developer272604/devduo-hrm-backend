<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class RefreshRun extends TenantRecord
{
    use HasPublicId;

    protected $table = 'analytics_refresh_runs';

    protected function casts(): array
    {
        return ['spec' => 'array', 'result' => 'array', 'refreshed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('This record is immutable.'));
        static::deleting(fn () => throw new \LogicException('This record is immutable.'));
    }
}
