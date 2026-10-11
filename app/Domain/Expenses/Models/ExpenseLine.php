<?php
namespace App\Domain\Expenses\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class ExpenseLine extends TenantRecord {
    
    protected $table = 'expense_lines';
    protected function casts(): array { return ['spent_on' => 'date:Y-m-d', 'amount_minor' => 'integer']; }
    
}
