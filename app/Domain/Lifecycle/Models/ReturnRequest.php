<?php
namespace App\Domain\Lifecycle\Models;
use App\Domain\Tenancy\{HasPublicId, TenantRecord};
class ReturnRequest extends TenantRecord {
    use HasPublicId;
    protected $table = 'lifecycle_returns';
    protected function casts(): array { return ['note' => 'encrypted', 'decision_note' => 'encrypted', 'returned_on' => 'date:Y-m-d']; }
}
