<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;

class BenefitEnrollmentWindow extends TenantRecord
{
    use HasPublicId;

    protected $table = 'benefit_enrollment_windows';

    protected function casts(): array
    {
        return [];
    }

    public function plan()
    {
        return $this->belongsTo(BenefitPlan::class, 'plan_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
