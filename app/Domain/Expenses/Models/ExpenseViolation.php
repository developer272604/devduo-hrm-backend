<?php
namespace App\Domain\Expenses\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class ExpenseViolation extends TenantRecord {
    use HasPublicId;
    protected $table = 'expense_violations';
    protected function casts(): array { return ['details' => 'array']; }
}
