<?php
namespace App\Domain\Expenses\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class ExpenseFxRate extends TenantRecord {
    use HasPublicId;
    protected $table = 'expense_fx_rates';
    protected function casts(): array { return ['effective_on' => 'date:Y-m-d', 'is_override' => 'boolean', 'decided_at' => 'datetime']; }
}
