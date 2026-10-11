<?php

namespace App\Domain\People\Records;

use App\Domain\People\Countries\Checksums;
use App\Domain\People\Countries\CountryConfig;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeDocument;
use Illuminate\Validation\ValidationException;

/** Validation shared by identity documents and work authorizations. Error messages never echo the submitted number. */
final class IdentityRules
{
    public const DEFAULT_PATTERN = '^[A-Z0-9]{4,30}$';

    public const VERIFICATION = ['unverified', 'verified', 'rejected'];

    public function __construct(private CountryConfig $countries) {}

    /** @return array the pack's definition of the type (label, pattern, mask, ...) */
    public function type(string $country, string $type, string $kind): array
    {
        $definition = $this->countries->identifierType($country, $type, $kind);
        if (! $definition) {
            throw ValidationException::withMessages(['type' => $kind === 'identity' ? 'Choose an identity type that is available for this country.' : 'Choose a work authorization type that is available for this country.']);
        }

        return $definition;
    }

    public function country(?string $value): string
    {
        $country = CountryConfig::normalizeCode((string) $value);
        if (! CountryConfig::validCode($country)) {
            throw ValidationException::withMessages(['country' => 'Choose a valid two-letter issuing country code, for example IN or GB.']);
        }

        return $country;
    }

    /** Normalizes and validates a number against the type's pattern and checksum. Returns the normalized number. */
    public function number(array $definition, string $number): string
    {
        $normalized = IdentityHash::normalize($number);
        $label = $definition['label'] ?? 'The number';
        $fail = fn () => throw ValidationException::withMessages(['number' => "$label does not look right. Check it and try again."]);
        if ($normalized === '' || mb_strlen($normalized) > 60) {
            $fail();
        }
        if (! CountryConfig::matches($definition['pattern'] ?? self::DEFAULT_PATTERN, $normalized)) {
            $fail();
        }
        if (! empty($definition['checksum']) && ! Checksums::check($definition['checksum'], $normalized)) {
            $fail();
        }

        return $normalized;
    }

    public function dates(?string $issue, ?string $expiry): void
    {
        if ($issue && $expiry && $expiry < $issue) {
            throw ValidationException::withMessages(['expiry_date' => 'The expiry date cannot be before the issue date.']);
        }
    }

    /** Resolves a linked document uuid to its internal id; it must belong to the same person. */
    public function document(Employee $employee, ?string $uuid): ?int
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }
        $id = EmployeeDocument::query()->where('person_id', $employee->person_id)->where('uuid', $uuid)->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['document_id' => 'Choose one of this employee\'s documents.']);
        }

        return $id;
    }

    public function documentUuid(?int $id): ?string
    {
        return $id ? EmployeeDocument::query()->withTrashed()->whereKey($id)->value('uuid') : null;
    }
}
