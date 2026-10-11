<?php
namespace App\Domain\Expenses\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class ExpensePolicy extends TenantRecord {
    use HasPublicId;
    protected $table = 'expense_policies';
    protected function casts(): array { return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'minor_units' => 'integer', 'max_amount_minor' => 'integer', 'receipt_threshold_minor' => 'integer']; }
    
}
