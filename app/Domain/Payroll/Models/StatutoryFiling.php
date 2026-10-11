<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class StatutoryFiling extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_statutory_filings';

    protected function casts(): array
    {
        return ['totals' => 'array'];
    }
}
