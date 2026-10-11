<?php

namespace App\Domain\Leave\Models;

use App\Domain\Tenancy\TenantRecord;
use LogicException;

/** Source-to-use trace: how much of a credit lot (source_entry_id) a debit (entry_id) consumed. Append-only. */
class LeaveLedgerAllocation extends TenantRecord
{
    public $timestamps = false;

    protected $table = 'leave_ledger_allocations';

    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new LogicException('Ledger allocations are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger allocations cannot be deleted.'));
    }
}
