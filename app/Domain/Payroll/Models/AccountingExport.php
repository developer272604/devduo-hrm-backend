<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class AccountingExport extends TenantRecord
{
    use HasPublicId;

    protected $table = 'accounting_exports';

    protected function casts(): array
    {
        return ['reconciliation' => 'array', 'total_debit' => 'decimal:4', 'total_credit' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(AccountingExportLine::class, 'export_id');
    }
}
