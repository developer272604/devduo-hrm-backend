<?php
namespace App\Domain\Expenses\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class ExpenseRateTable extends TenantRecord {
    use HasPublicId;
    protected $table = 'expense_rate_tables';
    protected function casts(): array { return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'version' => 'integer', 'minor_units' => 'integer']; }
}
