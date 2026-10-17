<?php

namespace App\Domain\Intelligence\Value;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class ValueEntry extends TenantRecord
{
    use HasPublicId;

    protected $table = 'value_ledger_entries';

    protected function casts(): array
    {
        return ['entry_month' => 'date:Y-m-d', 'amount' => 'decimal:4', 'hours' => 'decimal:2'];
    }
}
