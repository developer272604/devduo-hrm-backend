<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Goal extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_goals';

    protected function casts(): array
    {
        return ['weight' => 'decimal:2', 'progress' => 'decimal:2', 'start_value' => 'decimal:4', 'target_value' => 'decimal:4', 'current_value' => 'decimal:4', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'approved_at' => 'datetime'];
    }
    public function keyResults() { return $this->hasMany(KeyResult::class, 'goal_id'); }
    public function parent() { return $this->belongsTo(self::class, 'parent_id'); }
    public function children() { return $this->hasMany(self::class, 'parent_id'); }
}
