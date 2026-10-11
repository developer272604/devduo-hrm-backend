<?php
namespace App\Domain\Expenses\Models;
use App\Domain\Tenancy\TenantRecord;
class ExpenseLineAllocation extends TenantRecord {
    protected $table = 'expense_line_allocations';
    protected function casts(): array { return ['amount_minor' => 'integer']; }
}
