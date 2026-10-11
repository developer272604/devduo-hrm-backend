<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;

class ExchangeRate extends TenantRecord
{
    protected $table = 'currency_exchange_rates';

    protected function casts(): array
    {
        return ['rate' => 'decimal:10'];
    }
}
