<?php

namespace App\Domain\People\Sections;

use App\Domain\Access\Classification;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Email and phone records. A person has exactly one primary per kind among the contacts that are still current
 * (not end-dated before today). Ended contacts are kept as history.
 */
class ContactService extends PersonSection
{
    protected function modelClass(): string
    {
        return EmployeeContact::class;
    }

    protected function label(): string
    {
        return 'Contact';
    }

    protected function summaryLabel(Model $row): string
    {
        return $row->kind === 'email' ? 'Email address' : 'Phone number';
    }

    protected function eventStem(): string
    {
        return 'Contact';
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
        return $query->orderBy('kind')->orderByDesc('is_primary')->orderBy('id');
    }

    protected function creating(Employee $employee, array $data): array
    {
        $data['value'] = $this->normalize($data['kind'], $data['value']);
        $this->assertNotDuplicate($employee, $data['kind'], $data['value'], null);
        // The first current contact of a kind is the primary one.
        $data['is_primary'] = ($data['is_primary'] ?? false) || ! $this->current($employee, $data['kind'])->exists();

        return $data;
    }

    protected function updating(Employee $employee, Model $row, array $data): array
    {
        if (array_key_exists('value', $data)) {
            $data['value'] = $this->normalize($row->kind, $data['value']);
            $this->assertNotDuplicate($employee, $row->kind, $data['value'], $row->id);
            // A changed address or number has not been verified yet.
            if ($data['value'] !== $row->value) {
                $data['verified_at'] = null;
            }
        }
        if (array_key_exists('is_primary', $data) && ! $data['is_primary'] && $row->is_primary) {
            throw ValidationException::withMessages(['is_primary' => ['Choose another contact as the primary one instead of removing this flag.']]);
        }

        return $data;
    }

    protected function saved(Employee $employee, Model $row, bool $created): void
    {
        $this->normalizePrimary($employee, $row->kind, $row->is_primary && $this->isCurrent($row) ? $row->id : null);
    }

    protected function removed(Employee $employee, Model $row): void
    {
        $this->normalizePrimary($employee, $row->kind, null);
    }

    private function normalizePrimary(Employee $employee, string $kind, ?int $preferred): void
    {
        $current = $this->current($employee, $kind)->orderBy('id')->get();
        $this->enforcePrimary($current, $preferred);
        // Ended contacts are never primary.
        $this->query($employee)->where('kind', $kind)->where('is_primary', true)->whereNotIn('id', $current->pluck('id'))->update(['is_primary' => false]);
    }

    private function current(Employee $employee, string $kind)
    {
        $today = $this->today();

        return $this->query($employee)->where('kind', $kind)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today));
    }

    private function isCurrent(Model $row): bool
    {
        return $row->effective_to === null || $row->effective_to->format('Y-m-d') >= $this->today();
    }

    private function normalize(string $kind, string $value): string
    {
        $value = trim($value);

        return $kind === 'email' ? mb_strtolower($value) : preg_replace('/\s+/', ' ', $value);
    }

    private function assertNotDuplicate(Employee $employee, string $kind, string $value, ?int $ignoreId): void
    {
        $exists = $this->current($employee, $kind)->where('value', $value)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['value' => ['That '.($kind === 'email' ? 'email address' : 'phone number').' is already on file.']]);
        }
    }
}
