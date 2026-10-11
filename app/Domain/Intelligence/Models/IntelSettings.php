<?php

namespace App\Domain\Intelligence\Models;

use App\Domain\Tenancy\TenantRecord;

class IntelSettings extends TenantRecord
{
    protected $table = 'intelligence_settings';

    protected function casts(): array
    {
        return ['risk_signals_enabled' => 'boolean', 'min_cohort' => 'integer'];
    }

    /** Defaults when no row exists: risk signals OFF, cohort threshold 5. */
    public static function current(): self
    {
        return static::query()->first() ?? new static(['risk_signals_enabled' => false, 'min_cohort' => 5]);
    }
}
