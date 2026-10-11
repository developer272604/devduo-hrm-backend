<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class BankFileExport extends TenantRecord
{
    use HasPublicId;

    protected $table = 'bank_file_exports';

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'expires_at' => 'datetime', 'total_amount' => 'decimal:4'];
    }

    public function batch()
    {
        return $this->belongsTo(PaymentBatch::class, 'batch_id');
    }
}
