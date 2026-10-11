<?php

namespace App\Domain\People\Records;

use App\Domain\People\Countries\Checksums;
use App\Domain\People\Countries\CountryConfig;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeBankAccount;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee bank accounts for future payroll. The country-specific details are stored as
 * details_encrypted = Crypt::encryptString(json_encode($details)) (the format other modules rely on when they apply
 * approved change requests). Only the last four characters of the account identifier are kept in clear.
 * Accounts are retired, never deleted, so payment history stays traceable.
 */
final class BankService
{
    public function __construct(private CountryConfig $countries) {}

    public function list(Employee $employee): Collection
    {
        return EmployeeBankAccount::query()->where('person_id', $employee->person_id)->orderByRaw("CASE status WHEN 'active' THEN 0 ELSE 1 END")->orderByDesc('is_primary')->orderBy('id')->get();
    }

    public function find(Employee $employee, string $uuid): EmployeeBankAccount
    {
        return EmployeeBankAccount::query()->where('person_id', $employee->person_id)->where('uuid', $uuid)->firstOrFail();
    }

    public function create(User $actor, Employee $employee, array $data): EmployeeBankAccount
    {
        $country = $this->country($data['country'] ?? $employee->country);
        $details = $this->details($country, (array) ($data['details'] ?? []));
        $currency = $this->currency($country, $data['currency'] ?? null);
        $this->dates($data['effective_from'] ?? null, $data['effective_to'] ?? null);

        return DB::transaction(function () use ($employee, $data, $country, $details, $currency) {
            $hasActive = EmployeeBankAccount::query()->where('person_id', $employee->person_id)->where('status', 'active')->exists();
            $primary = (bool) ($data['is_primary'] ?? false) || ! $hasActive;
            if ($primary) {
                $this->clearPrimary($employee);
            }
            $row = EmployeeBankAccount::query()->create([
                'person_id' => $employee->person_id, 'account_holder' => trim($data['account_holder']), 'bank_name' => trim($data['bank_name']), 'country' => $country, 'currency' => $currency,
                'details_encrypted' => $this->encrypt($details), 'account_last4' => $this->last4($country, $details), 'is_primary' => $primary, 'status' => 'active',
                'effective_from' => $data['effective_from'] ?? null, 'effective_to' => $data['effective_to'] ?? null,
            ]);
            RecordLog::audit('created', $employee, 'bank', ['record' => $row->uuid, 'country' => $country, 'currency' => $currency, 'primary' => $primary]);
            RecordLog::event('BankAccountChanged', $employee, 'Bank account added.', 'highly_sensitive', ['employee_bank_account', $row->id], ['operation' => 'created']);

            return $row;
        });
    }

    public function update(User $actor, Employee $employee, EmployeeBankAccount $row, array $data): EmployeeBankAccount
    {
        if ($row->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'A retired bank account cannot be changed. Add a new account instead.']);
        }
        $country = array_key_exists('country', $data) ? $this->country($data['country']) : $row->country;
        $detailsChanged = array_key_exists('details', $data) || $country !== $row->country;
        $details = null;
        if ($detailsChanged) {
            if (! array_key_exists('details', $data)) {
                throw ValidationException::withMessages(['details' => 'Enter the account details for the new country.']);
            }
            $details = $this->details($country, (array) $data['details']);
        }
        $from = array_key_exists('effective_from', $data) ? $data['effective_from'] : $row->effective_from?->toDateString();
        $to = array_key_exists('effective_to', $data) ? $data['effective_to'] : $row->effective_to?->toDateString();
        $this->dates($from, $to);
        $currency = array_key_exists('currency', $data) || $country !== $row->country ? $this->currency($country, $data['currency'] ?? null) : $row->currency;

        return DB::transaction(function () use ($employee, $row, $data, $country, $details, $currency, $from, $to, $detailsChanged) {
            $fields = ['country' => $country, 'currency' => $currency, 'effective_from' => $from, 'effective_to' => $to];
            foreach (['account_holder', 'bank_name'] as $plain) {
                if (array_key_exists($plain, $data)) {
                    $fields[$plain] = trim($data[$plain]);
                }
            }
            if ($details !== null) {
                $fields += ['details_encrypted' => $this->encrypt($details), 'account_last4' => $this->last4($country, $details)];
            }
            if (! empty($data['is_primary'])) {
                $this->clearPrimary($employee, $row->id);
                $fields['is_primary'] = true;
            } elseif (array_key_exists('is_primary', $data)) {
                $fields['is_primary'] = false;
            }
            $row->fill($fields)->save();
            RecordLog::audit('updated', $employee, 'bank', ['record' => $row->uuid, 'details_changed' => $detailsChanged, 'fields' => array_keys($data)]);
            RecordLog::event('BankAccountChanged', $employee, 'Bank account updated.', 'highly_sensitive', ['employee_bank_account', $row->id], ['operation' => 'updated', 'details_changed' => $detailsChanged]);

            return $row->refresh();
        });
    }

    /** Retires an account (kept for history). A retired account cannot be primary. */
    public function retire(User $actor, Employee $employee, EmployeeBankAccount $row, ?string $effectiveTo): EmployeeBankAccount
    {
        if ($row->status === 'retired') {
            throw ValidationException::withMessages(['status' => 'This bank account is already retired.']);
        }

        return DB::transaction(function () use ($employee, $row, $effectiveTo) {
            $row->update(['status' => 'retired', 'is_primary' => false, 'effective_to' => $effectiveTo ?? TenantClock::today()]);
            RecordLog::audit('retired', $employee, 'bank', ['record' => $row->uuid]);
            RecordLog::event('BankAccountChanged', $employee, 'Bank account retired.', 'highly_sensitive', ['employee_bank_account', $row->id], ['operation' => 'retired']);

            return $row->refresh();
        });
    }

    /** Decrypted details. Always audited by the caller-facing action (reveal); the values never enter audit context. */
    public function reveal(User $actor, Employee $employee, EmployeeBankAccount $row): array
    {
        $details = $this->decrypt($row);
        RecordLog::audit('revealed', $employee, 'bank', ['record' => $row->uuid]);

        return $details ?? [];
    }

    /** Masked representation: values are shortened to the last four characters (option fields stay readable). */
    public function present(EmployeeBankAccount $row): array
    {
        $schema = collect($this->countries->bankSchema($row->country))->keyBy('key');
        $masked = [];
        $details = $this->decrypt($row, false);
        foreach ($details ?? [] as $key => $value) {
            $field = $schema->get($key, []);
            $masked[$key] = ! empty($field['options']) ? (string) $value : Masking::value((string) $value);
        }

        return ['uuid' => $row->uuid, 'account_holder' => $row->account_holder, 'bank_name' => $row->bank_name, 'country' => $row->country, 'currency' => $row->currency,
            'account_last4' => $row->account_last4, 'account_masked' => $row->account_last4 ? Masking::fromLast4($row->account_last4) : null, 'details' => $masked ?: new \stdClass,
            'is_primary' => $row->is_primary, 'status' => $row->status, 'effective_from' => $row->effective_from?->toDateString(), 'effective_to' => $row->effective_to?->toDateString(),
            'created_at' => $row->created_at?->toIso8601String(), 'updated_at' => $row->updated_at?->toIso8601String()];
    }

    /** Validates and normalizes details against the country's bank schema. Messages never echo the submitted values. */
    public function details(string $country, array $input): array
    {
        $errors = [];
        $out = [];
        foreach ($this->countries->bankSchema($country) as $field) {
            $key = $field['key'];
            $raw = $input[$key] ?? null;
            $label = $field['label'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                if (! empty($field['required'])) {
                    $errors["details.$key"] = "$label is required for bank accounts in this country.";
                }

                continue;
            }
            if (! is_scalar($raw) || mb_strlen((string) $raw) > 120) {
                $errors["details.$key"] = "$label does not look right.";

                continue;
            }
            $value = $this->normalizeValue($field, (string) $raw);
            if (! empty($field['options'])) {
                $allowed = array_map(fn ($o) => is_array($o) ? (string) $o['value'] : (string) $o, $field['options']);
                if (! in_array($value, $allowed, true)) {
                    $errors["details.$key"] = "Choose a valid $label.";

                    continue;
                }
            }
            if (! CountryConfig::matches($field['pattern'] ?? null, $value) || (! empty($field['checksum']) && ! Checksums::check($field['checksum'], $value))) {
                $errors["details.$key"] = "$label does not look right. Check it and try again.";

                continue;
            }
            $out[$key] = $value;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    private function normalizeValue(array $field, string $value): string
    {
        $value = trim($value);

        return match ($field['normalize'] ?? 'trim') {
            'digits' => preg_replace('/[\s\-.]+/', '', $value) ?? $value,
            'upper' => IdentityHash::normalize($value),
            default => $value,
        };
    }

    private function last4(string $country, array $details): ?string
    {
        foreach ($this->countries->bankSchema($country) as $field) {
            if (! empty($field['primary']) && isset($details[$field['key']])) {
                return mb_substr((string) $details[$field['key']], -4);
            }
        }
        $first = reset($details);

        return $first !== false ? mb_substr((string) $first, -4) : null;
    }

    private function encrypt(array $details): string
    {
        return Crypt::encryptString(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return array<string,string>|null */
    private function decrypt(EmployeeBankAccount $row, bool $failHard = true): ?array
    {
        try {
            return json_decode(Crypt::decryptString($row->details_encrypted), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            abort_if($failHard, 500, 'This record cannot be decrypted. Contact your administrator.');

            return null;
        }
    }

    private function country(?string $value): string
    {
        $country = CountryConfig::normalizeCode((string) $value);
        if (! CountryConfig::validCode($country)) {
            throw ValidationException::withMessages(['country' => 'Choose a valid two-letter country code, for example IN or GB.']);
        }
        if (! $this->countries->isEnabled($country)) {
            throw ValidationException::withMessages(['country' => 'This country is not enabled for your workspace.']);
        }

        return $country;
    }

    private function currency(string $country, ?string $value): string
    {
        $currency = strtoupper(trim((string) ($value ?: ($this->countries->for($country)['currency'] ?? ''))));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['currency' => 'Enter a three-letter currency code, for example INR.']);
        }

        return $currency;
    }

    private function dates(?string $from, ?string $to): void
    {
        if ($from && $to && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be before the start date.']);
        }
    }

    private function clearPrimary(Employee $employee, ?int $exceptId = null): void
    {
        EmployeeBankAccount::query()->where('person_id', $employee->person_id)->where('is_primary', true)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->update(['is_primary' => false]);
    }
}
