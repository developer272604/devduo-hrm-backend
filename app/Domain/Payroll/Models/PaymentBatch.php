<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;

class PaymentBatch extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payment_batches';

    protected function casts(): array
    {
        return ['source_account' => 'array', 'exceptions' => 'array', 'reconciliation' => 'array', 'prepared_at' => 'datetime', 'submitted_at' => 'datetime', 'confirmed_at' => 'datetime', 'total_amount' => 'decimal:4'];
    }

    public function instructions()
    {
        return $this->hasMany(PaymentInstruction::class, 'batch_id');
    }

    public function exports()
    {
        return $this->hasMany(BankFileExport::class, 'batch_id');
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
