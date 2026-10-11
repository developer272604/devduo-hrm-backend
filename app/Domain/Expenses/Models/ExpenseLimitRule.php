<?php
namespace App\Domain\Expenses\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class ExpenseLimitRule extends TenantRecord {
    use HasPublicId;
    protected $table = 'expense_limit_rules';
    protected function casts(): array { return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'version' => 'integer', 'minor_units' => 'integer', 'per_line_limit_minor' => 'integer', 'category_total_limit_minor' => 'integer', 'receipt_over_minor' => 'integer', 'receipt_always' => 'boolean']; }
}
