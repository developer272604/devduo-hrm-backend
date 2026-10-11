<?php
namespace App\Domain\Assets\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class AssetMaintenance extends TenantRecord {
    use HasPublicId;
    protected $table = 'asset_maintenance';
    protected function casts(): array { return ['started_on' => 'date:Y-m-d', 'completed_on' => 'date:Y-m-d', 'cost_minor' => 'integer', 'minor_units' => 'integer']; }
}
