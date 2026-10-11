<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use LogicException;

class PayrollPeriodTransition extends TenantRecord
{
    protected $table = 'payroll_period_transitions';

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    /** Append-only: history is corrected with new rows, never edited. */
    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new LogicException('PayrollPeriodTransition rows are immutable. Insert a new row instead.'));
        static::deleting(fn () => throw new LogicException('PayrollPeriodTransition rows cannot be deleted.'));
    }
}
