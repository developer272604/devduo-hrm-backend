<?php

namespace App\Domain\Performance\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Pip extends TenantRecord
{
    use HasPublicId;

    protected $table = 'performance_pips';

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'support_plan' => 'encrypted', 'outcome_note' => 'encrypted', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }
    public function checkpoints() { return $this->hasMany(PipCheckpoint::class, 'pip_id')->orderBy('due_on'); }
}
