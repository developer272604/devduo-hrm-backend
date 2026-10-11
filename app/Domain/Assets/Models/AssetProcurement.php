<?php
namespace App\Domain\Assets\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class AssetProcurement extends TenantRecord {
    use HasPublicId;
    protected $table = 'asset_procurement';
    protected function casts(): array { return ['purchased_on' => 'date:Y-m-d', 'warranty_until' => 'date:Y-m-d', 'cost_minor' => 'integer', 'salvage_minor' => 'integer', 'minor_units' => 'integer', 'useful_life_months' => 'integer']; }
}
