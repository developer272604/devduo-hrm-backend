<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeDependent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Dependents. History is preserved by end-dating (effective_to) rather than deleting; benefit_eligibility is
 * metadata for a later benefits module and is not interpreted here.
 */
class DependentService extends PersonSection
{
    protected function modelClass(): string
    {
        return EmployeeDependent::class;
    }

    protected function label(): string
    {
        return 'Dependent';
    }

    protected function eventStem(): string
    {
        return 'Dependent';
    }

    protected function classification(): string
    {
        return Classification::EMPLOYEE;
    }

    protected function redactValues(): bool
    {
        return true;
    }

    protected function datePairs(): array
    {
        return [['effective_from', 'effective_to', 'The end date cannot be before the start date.']];
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderBy('status')->orderBy('id');
    }

    protected function updating(Employee $employee, Model $row, array $data): array
    {
        // End-dating a dependent in the past or today also marks them inactive unless the caller says otherwise.
        if (! empty($data['effective_to']) && $data['effective_to'] <= $this->today() && ! array_key_exists('status', $data)) {
            $data['status'] = 'inactive';
        }

        return $data;
    }
}
