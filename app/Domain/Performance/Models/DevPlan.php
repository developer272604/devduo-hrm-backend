<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class DevPlan extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_development_plans';

    protected function casts(): array
    {
        return ['target_date' => 'date:Y-m-d'];
    }
    public function actions() { return $this->hasMany(DevAction::class, 'plan_id'); }
}
