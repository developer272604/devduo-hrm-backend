<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Tenancy\TenantRecord;

/** Immutable audit trail of workflow steps for a regularization (no public id; always reached through its request). */
class RegularizationAction extends TenantRecord
{
    protected $table = 'attendance_regularization_actions';

    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }
}
