<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeEducation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EducationService extends PersonSection
{
    /** Facts a reviewer verified: changing any of them voids the verification. */
    private const VERIFIED_FACTS = ['institution', 'qualification', 'field_of_study', 'start_date', 'end_date', 'grade', 'country', 'document_id'];

    protected function modelClass(): string
    {
        return EmployeeEducation::class;
    }

    protected function label(): string
    {
        return 'Education record';
    }

    protected function eventStem(): string
    {
        return 'Education';
    }

    protected function classification(): string
    {
        return Classification::PUBLIC_INTERNAL;
    }

    protected function datePairs(): array
    {
        return [['start_date', 'end_date', 'The end date cannot be before the start date.']];
    }

    protected function descriptor(Model $row): ?string
    {
        return $row->qualification.', '.$row->institution;
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderByRaw('end_date IS NULL DESC')->orderByDesc('end_date')->orderByDesc('id');
    }

    protected function creating(Employee $employee, array $data): array
    {
        return $data + ['verification_status' => 'unverified'];
    }

    protected function updating(Employee $employee, Model $row, array $data): array
    {
        return $this->resetVerification($row, $data, self::VERIFIED_FACTS);
    }
}
