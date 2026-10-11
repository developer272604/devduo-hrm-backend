<?php

namespace App\Domain\Analytics\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class Scenario extends TenantRecord
{
    use HasPublicId;

    protected $table = 'analytics_scenarios';

    protected function casts(): array
    {
        return ['assumptions' => 'array', 'result' => 'array'];
    }

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new \LogicException('This record is immutable.'));
        static::deleting(fn () => throw new \LogicException('This record is immutable.'));
    }
}
