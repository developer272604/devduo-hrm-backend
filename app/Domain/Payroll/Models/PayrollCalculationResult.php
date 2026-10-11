<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\TenantRecord;
use App\Domain\Tenancy\HasPublicId;
use LogicException;

class PayrollCalculationResult extends TenantRecord
{
    use HasPublicId;

    protected $table = 'payroll_calculation_results';

    protected function casts(): array
    {
        return ['is_current' => 'boolean', 'input_snapshot' => 'encrypted:array', 'trace' => 'encrypted:array', 'warnings' => 'array', 'errors' => 'array', 'gross' => 'decimal:4', 'total_deductions' => 'decimal:4', 'total_taxes' => 'decimal:4', 'employer_contributions' => 'decimal:4', 'benefits' => 'decimal:4', 'reimbursements' => 'decimal:4', 'net' => 'decimal:4', 'payable_days' => 'decimal:4', 'working_days' => 'decimal:4'];
    }

    /** Immutable payroll result: only the supersession bookkeeping columns may change; figures and traces never. */
    public const MUTABLE = ['is_current', 'superseded_by_id', 'updated_at'];

    protected static function booted(): void
    {
        parent::booted();
        static::updating(function (self $m) {
            $illegal = array_diff(array_keys($m->getDirty()), self::MUTABLE);
            if ($illegal) {
                throw new LogicException('Payroll results are immutable (attempted to change: '.implode(', ', $illegal).'). Post a new calculation version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Payroll results cannot be deleted.'));
    }

    public function components()
    {
        return $this->hasMany(PayrollResultComponent::class, 'result_id');
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
