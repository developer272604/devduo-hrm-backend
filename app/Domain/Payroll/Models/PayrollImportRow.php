<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;

class PayrollImportRow extends TenantRecord
{
    protected $table = 'payroll_import_rows';

    protected function casts(): array
    {
        return ['raw' => 'encrypted:array', 'errors' => 'array'];
    }

    public function import()
    {
        return $this->belongsTo(PayrollImport::class, 'import_id');
    }
}
