<?php
namespace App\Domain\Expenses\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class ExpenseReceipt extends TenantRecord {
    use HasPublicId;
    protected $table = 'expense_receipts';
    protected function casts(): array { return ['size_bytes' => 'integer']; }
    
}
