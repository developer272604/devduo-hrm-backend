<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;
use LogicException;

class PayrollResultComponent extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_result_components';

    protected function casts(): array
    {
        return ['trace' => 'array', 'payslip_visible' => 'boolean', 'amount' => 'decimal:4', 'taxable_amount' => 'decimal:4', 'quantity' => 'decimal:6'];
    }

    /** Append-only: history is corrected with new rows, never edited. */
    protected static function booted(): void
    {
        parent::booted();
        static::updating(fn () => throw new LogicException('PayrollResultComponent rows are immutable. Insert a new row instead.'));
        static::deleting(fn () => throw new LogicException('PayrollResultComponent rows cannot be deleted.'));
    }

    public function result()
    {
        return $this->belongsTo(PayrollCalculationResult::class, 'result_id');
    }
}
