<?php

namespace App\Domain\People\Models;

use App\Domain\Tenancy\TenantRecord;

class ReportingRelationship extends TenantRecord
{
    protected $table = 'employee_reporting_relationships';

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function manager()
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }
}
