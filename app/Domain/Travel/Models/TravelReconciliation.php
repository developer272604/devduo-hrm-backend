<?php
namespace App\Domain\Travel\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class TravelReconciliation extends TenantRecord {
    use HasPublicId;
    protected $table = 'travel_reconciliations';
    protected function casts(): array { return ['claims' => 'array', 'closed_at' => 'datetime', 'budget_minor' => 'integer', 'actual_minor' => 'integer', 'advance_minor' => 'integer', 'variance_minor' => 'integer', 'settlement_minor' => 'integer', 'minor_units' => 'integer']; }
}
