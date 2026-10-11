<?php
namespace App\Domain\Assets\Models;
use App\Domain\Tenancy\{TenantRecord, HasPublicId};
class Asset extends TenantRecord {
    use HasPublicId;
    protected $attributes = ['status' => 'available', 'condition' => 'good', 'version' => 0];
    protected function casts(): array { return ['warranty_until' => 'date:Y-m-d', 'acknowledged_at' => 'datetime', 'version' => 'integer']; }
    public function employee() { return $this->belongsTo(\App\Domain\People\Models\Employee::class); }
    public function movements() { return $this->hasMany(AssetMovement::class)->orderByDesc('id'); }
}
