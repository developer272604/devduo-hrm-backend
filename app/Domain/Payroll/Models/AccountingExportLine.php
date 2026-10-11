<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;

class AccountingExportLine extends TenantRecord
{
    protected $table = 'accounting_export_lines';

    protected function casts(): array
    {
        return ['dimensions' => 'array', 'debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    public function export()
    {
        return $this->belongsTo(AccountingExport::class, 'export_id');
    }
}
