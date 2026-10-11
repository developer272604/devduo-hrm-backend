<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\TenantRecord;

class EmployeeMilestoneDelivery extends TenantRecord
{
    protected $table = 'experience_milestone_deliveries';
    protected function casts(): array { return ['milestone_date' => 'date:Y-m-d', 'sent_at' => 'datetime', 'suppressed_at' => 'datetime']; }
}
