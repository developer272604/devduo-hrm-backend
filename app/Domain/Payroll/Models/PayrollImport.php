<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PayrollImport extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_imports';

    protected function casts(): array
    {
        return ['mapping' => 'array'];
    }

    public function rows()
    {
        return $this->hasMany(PayrollImportRow::class, 'import_id');
    }
}
