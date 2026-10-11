<?php
namespace App\Domain\Assets\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class OperationsPreview extends TenantRecord {
    use HasPublicId;
    protected $table = 'operations_previews';
    protected function casts(): array { return ['payload' => 'array', 'summary' => 'array', 'expires_at' => 'datetime', 'committed_at' => 'datetime']; }
}
