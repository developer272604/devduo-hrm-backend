<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeEmergencyContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Emergency contacts. Exactly one is primary; values (phones, address) never appear in audit or event text. */
class EmergencyContactService extends PersonSection
{
    protected function modelClass(): string
    {
        return EmployeeEmergencyContact::class;
    }

    protected function label(): string
    {
        return 'Emergency contact';
    }

    protected function eventStem(): string
    {
        return 'EmergencyContact';
    }

    protected function classification(): string
    {
        return Classification::EMPLOYEE;
    }

    protected function redactValues(): bool
    {
        return true;
    }

    protected function orderBy(Builder $query): Builder
    {
        return $query->orderByDesc('is_primary')->orderBy('id');
    }

    protected function creating(Employee $employee, array $data): array
    {
        $data['is_primary'] = ($data['is_primary'] ?? false) || ! $this->query($employee)->exists();

        return $data;
    }

    protected function updating(Employee $employee, Model $row, array $data): array
    {
        if (array_key_exists('is_primary', $data) && ! $data['is_primary'] && $row->is_primary) {
            throw ValidationException::withMessages(['is_primary' => ['Choose another emergency contact as the primary one instead of removing this flag.']]);
        }

        return $data;
    }

    protected function saved(Employee $employee, Model $row, bool $created): void
    {
        $this->enforcePrimary($this->query($employee)->orderBy('id')->get(), $row->is_primary ? $row->id : null);
    }

    protected function removed(Employee $employee, Model $row): void
    {
        $this->enforcePrimary($this->query($employee)->orderBy('id')->get(), null);
    }
}
