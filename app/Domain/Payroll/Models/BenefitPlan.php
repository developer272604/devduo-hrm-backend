<?php

namespace App\Domain\Payroll\Models;

use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\HasPublicId;
use App\Domain\Tenancy\TenantRecord;
use Carbon\CarbonImmutable;

class BenefitPlan extends TenantRecord
{
    use HasPublicId;

    protected $table = 'benefit_plans';

    protected function casts(): array
    {
        return ['provider' => 'array', 'eligibility' => 'array', 'contributions' => 'array'];
    }

    public function enrollmentWindows()
    {
        return $this->hasMany(BenefitEnrollmentWindow::class, 'plan_id')->orderBy('opens_on');
    }

    public function eligibleFor(Employee $employee): bool
    {
        $rules = $this->eligibility ?? [];
        if ($this->country && $employee->country !== $this->country) {
            return false;
        }
        if ($this->effective_from > now($employee->timezone)->toDateString() || ($this->effective_to && $this->effective_to < now($employee->timezone)->toDateString())) {
            return false;
        }
        if (isset($rules['worker_types']) && ! in_array($employee->worker_type, $rules['worker_types'], true)) {
            return false;
        }
        if (isset($rules['countries']) && ! in_array($employee->country, $rules['countries'], true)) {
            return false;
        }
        if (isset($rules['legal_entity_ids'])) {
            $entity = $employee->assignmentOn(now($employee->timezone)->toDateString())?->legal_entity_id;
            if (! $entity || ! in_array((int) $entity, array_map('intval', $rules['legal_entity_ids']), true)) {
                return false;
            }
        }
        if (isset($rules['min_tenure_days'])) {
            $start = $employee->employment_start_date ?? $employee->joined_on;
            if (! $start || CarbonImmutable::parse($start)->diffInDays(now($employee->timezone)->startOfDay()) < (int) $rules['min_tenure_days']) {
                return false;
            }
        }

        return true;
    }
}
