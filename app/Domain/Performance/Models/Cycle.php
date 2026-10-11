<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Cycle extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_cycles';

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'self_due' => 'date:Y-m-d', 'manager_due' => 'date:Y-m-d', 'peer_due' => 'date:Y-m-d', 'calibration_due' => 'date:Y-m-d', 'eligibility' => 'array', 'settings' => 'array', 'opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }
    public function template() { return $this->belongsTo(Template::class, 'template_id'); }
}
