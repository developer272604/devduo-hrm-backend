<?php
namespace App\Domain\Expenses\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class ExpenseClaim extends TenantRecord {
    use HasPublicId;
    protected $table = 'expense_claims';
    protected function casts(): array { return ['policy_snapshot' => 'array', 'minor_units' => 'integer', 'total_minor' => 'integer', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'paid_at' => 'datetime']; }
    public function lines() { return $this->hasMany(ExpenseLine::class, 'claim_id'); }
    public function receipts() { return $this->hasMany(ExpenseReceipt::class, 'claim_id'); }
}
