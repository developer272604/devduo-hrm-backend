<?php

namespace App\Domain\People\Records;

use App\Domain\People\Countries\CountryConfig;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Addresses belong to the person (they survive rehire). Validation is country-driven through CountryConfig. */
final class AddressService
{
    public function __construct(private CountryConfig $countries) {}

    public function list(Employee $employee): Collection
    {
        return EmployeeAddress::query()->where('person_id', $employee->person_id)->orderBy('type')->orderByDesc('is_primary')->orderByDesc('effective_from')->orderBy('id')->get();
    }

    public function find(Employee $employee, string $uuid): EmployeeAddress
    {
        return EmployeeAddress::query()->where('person_id', $employee->person_id)->where('uuid', $uuid)->firstOrFail();
    }

    public function create(Employee $employee, array $data): EmployeeAddress
    {
        $row = $this->normalize($data);

        return DB::transaction(function () use ($employee, $row) {
            $hasType = EmployeeAddress::query()->where('person_id', $employee->person_id)->where('type', $row['type'])->exists();
            $primary = $row['is_primary'] || ! $hasType;
            if ($primary) {
                $this->clearPrimary($employee, $row['type']);
            }
            $address = EmployeeAddress::query()->create(['person_id' => $employee->person_id, 'is_primary' => $primary] + $row);
            RecordLog::audit('created', $employee, 'address', ['record' => $address->uuid, 'type' => $address->type, 'country' => $address->country]);
            RecordLog::event('AddressChanged', $employee, ucfirst($address->type).' address added.', 'employee', ['employee_address', $address->id], ['operation' => 'created', 'type' => $address->type]);

            return $address;
        });
    }

    public function update(Employee $employee, EmployeeAddress $address, array $data): EmployeeAddress
    {
        $merged = array_merge($address->only(['type', 'line1', 'line2', 'city', 'region', 'postal_code', 'country', 'extra', 'is_primary']),
            ['effective_from' => $address->effective_from?->toDateString(), 'effective_to' => $address->effective_to?->toDateString()], $data);
        if (isset($data['extra']) && is_array($data['extra'])) {
            $merged['extra'] = $data['extra'];
        }
        $row = $this->normalize($merged);

        return DB::transaction(function () use ($employee, $address, $row, $data) {
            $oldType = $address->type;
            $changedType = $row['type'] !== $oldType;
            $primary = array_key_exists('is_primary', $data) ? (bool) $data['is_primary'] : $address->is_primary;
            if ($changedType) {
                $primary = ! EmployeeAddress::query()->where('person_id', $employee->person_id)->where('type', $row['type'])->exists() || $primary;
            }
            if ($primary) {
                $this->clearPrimary($employee, $row['type'], $address->id);
            }
            $address->fill(['is_primary' => $primary] + $row)->save();
            if ($changedType) {
                $this->ensurePrimary($employee, $oldType);
            }
            RecordLog::audit('updated', $employee, 'address', ['record' => $address->uuid, 'type' => $address->type, 'fields' => array_keys($data)]);
            RecordLog::event('AddressChanged', $employee, ucfirst($address->type).' address updated.', 'employee', ['employee_address', $address->id], ['operation' => 'updated', 'type' => $address->type]);

            return $address->refresh();
        });
    }

    public function delete(Employee $employee, EmployeeAddress $address): void
    {
        DB::transaction(function () use ($employee, $address) {
            $type = $address->type;
            $wasPrimary = $address->is_primary;
            $address->delete();
            if ($wasPrimary) {
                $this->ensurePrimary($employee, $type);
            }
            RecordLog::audit('deleted', $employee, 'address', ['record' => $address->uuid, 'type' => $type]);
            RecordLog::event('AddressChanged', $employee, ucfirst($type).' address removed.', 'employee', ['employee_address', $address->id], ['operation' => 'deleted', 'type' => $type]);
        });
    }

    public function present(EmployeeAddress $address): array
    {
        $arr = ['line1' => $address->line1, 'line2' => $address->line2, 'city' => $address->city, 'region' => $address->region, 'postal_code' => $address->postal_code, 'extra' => $address->extra];

        return ['uuid' => $address->uuid, 'type' => $address->type, 'line1' => $address->line1, 'line2' => $address->line2, 'city' => $address->city, 'region' => $address->region,
            'postal_code' => $address->postal_code, 'country' => $address->country, 'extra' => $address->extra ?: new \stdClass, 'is_primary' => $address->is_primary,
            'effective_from' => $address->effective_from?->toDateString(), 'effective_to' => $address->effective_to?->toDateString(), 'formatted' => $this->countries->formatAddress($address->country, $arr),
            'updated_at' => $address->updated_at?->toIso8601String()];
    }

    /** Applies country rules and returns the column payload. */
    private function normalize(array $data): array
    {
        $country = CountryConfig::normalizeCode((string) ($data['country'] ?? ''));
        if (! CountryConfig::validCode($country)) {
            throw ValidationException::withMessages(['country' => 'Choose a valid two-letter country code, for example IN or GB.']);
        }
        if (! $this->countries->isEnabled($country)) {
            throw ValidationException::withMessages(['country' => 'This country is not enabled for your workspace.']);
        }
        $from = $data['effective_from'] ?? null;
        $to = $data['effective_to'] ?? null;
        if ($from && $to && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be before the start date.']);
        }
        $address = $this->countries->validateAddress($country, $data);

        return $address + ['type' => $data['type'], 'country' => $country, 'is_primary' => (bool) ($data['is_primary'] ?? false), 'effective_from' => $from, 'effective_to' => $to];
    }

    private function clearPrimary(Employee $employee, string $type, ?int $exceptId = null): void
    {
        EmployeeAddress::query()->where('person_id', $employee->person_id)->where('type', $type)->where('is_primary', true)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->update(['is_primary' => false]);
    }

    /** Keeps the invariant "a type that has addresses has exactly one primary". */
    private function ensurePrimary(Employee $employee, string $type): void
    {
        $rows = EmployeeAddress::query()->where('person_id', $employee->person_id)->where('type', $type)->orderByDesc('effective_from')->orderByDesc('id')->get();
        if ($rows->isNotEmpty() && ! $rows->contains('is_primary', true)) {
            $rows->first()->update(['is_primary' => true]);
        }
    }
}
