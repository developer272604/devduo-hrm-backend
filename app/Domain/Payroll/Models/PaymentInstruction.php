<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PaymentInstruction extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payment_instructions';

    protected function casts(): array
    {
        return ['beneficiary' => 'encrypted:array', 'paid_at' => 'datetime', 'amount' => 'decimal:4'];
    }

    public function batch()
    {
        return $this->belongsTo(PaymentBatch::class, 'batch_id');
    }
}
