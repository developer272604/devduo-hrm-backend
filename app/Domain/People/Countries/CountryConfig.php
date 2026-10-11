<?php

namespace App\Domain\People\Countries;

use App\Domain\People\Models\CountryConfiguration;
use Illuminate\Validation\ValidationException;

/**
 * Country configuration architecture: default packs (config/hrm_countries.php) merged with the tenant's
 * country_configurations row. Adding a country, or changing a pack, never needs a schema or code change.
 *
 * Merge rules: scalar overrides replace the default when not null; list overrides (address fields, identifier types,
 * bank schema, employment and statutory fields) merge BY KEY - an override entry replaces the default entry with the same
 * key, unknown keys are appended and {"key": "...", "disabled": true} removes an entry.
 */
class CountryConfig
{
    public const SCALARS = ['name', 'currency', 'timezone', 'locale', 'date_format', 'number_format', 'phone_code', 'name_format', 'working_language'];

    public const LISTS = ['address_format', 'identifier_types', 'bank_schema', 'employment_fields', 'statutory_fields'];

    /** Address fields stored in real columns; every other pack field is kept in `extra`. */
    public const ADDRESS_COLUMNS = ['line1', 'line2', 'city', 'region', 'postal_code'];

    /** @var array<string,array> */
    private array $packs = [];

    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function validCode(string $code): bool
    {
        return (bool) preg_match('/^[A-Z]{2}$/', $code);
    }

    /** Compiles a pack pattern (stored without delimiters) into a safe regex. */
    public static function regex(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~u';
    }

    public static function matches(?string $pattern, string $value): bool
    {
        if ($pattern === null || $pattern === '') {
            return true;
        }

        return @preg_match(self::regex($pattern), $value) === 1;
    }

    public static function patternIsValid(string $pattern): bool
    {
        return strlen($pattern) <= 300 && @preg_match(self::regex($pattern), '') !== false;
    }

    /** The shipped pack for a code, or null when the country has none. */
    public function defaultPack(string $code): ?array
    {
        $pack = config('hrm_countries.countries.'.self::normalizeCode($code));

        return is_array($pack) ? $pack : null;
    }

    public function override(string $code): ?CountryConfiguration
    {
        return CountryConfiguration::query()->where('country', self::normalizeCode($code))->first();
    }

    /** True when the country has a shipped pack or a tenant-defined row. */
    public function known(string $code): bool
    {
        return $this->defaultPack($code) !== null || $this->override($code) !== null;
    }

    /**
     * The effective configuration for a country: default pack (or the generic pack for unknown countries)
     * merged with the tenant override. Always returns a complete structure.
     */
    public function for(string $code): array
    {
        $code = self::normalizeCode($code);
        if (isset($this->packs[$code])) {
            return $this->packs[$code];
        }
        $default = $this->defaultPack($code);
        $generic = (array) config('hrm_countries.generic');
        $row = $this->override($code);
        $base = $default ?? $generic;
        $pack = ['code' => $code, 'enabled' => true, 'source' => $default ? ($row ? 'override' : 'default') : ($row ? 'custom' : 'generic'), 'generic' => $default === null && $row === null];

        foreach (self::SCALARS as $field) {
            $pack[$field] = $row?->{$field} ?? $base[$field] ?? ($field === 'name' ? $code : null);
        }
        foreach (self::LISTS as $field) {
            $baseList = $base[$field] ?? [];
            if ($field === 'identifier_types') {
                // Generic identifier / work-authorization types are always available beside country-specific ones.
                $keys = array_column($baseList, 'key');
                foreach ($generic['identifier_types'] as $item) {
                    if (! in_array($item['key'], $keys, true)) {
                        $baseList[] = $item;
                    }
                }
            }
            $pack[$field] = $this->mergeList($baseList, $row?->{$field});
        }
        $pack['address_format'] = array_map([$this, 'normalizeField'], $pack['address_format']);
        $pack['bank_schema'] = array_map([$this, 'normalizeField'], $pack['bank_schema']);
        $pack['enabled'] = $row ? (bool) $row->enabled : true;

        return $this->packs[$code] = $pack;
    }

    /** Enabled countries (shipped packs plus tenant-defined ones), summarised for pickers, sorted by name. */
    public function all(): array
    {
        $codes = array_keys((array) config('hrm_countries.countries'));
        $rows = CountryConfiguration::query()->pluck('country')->all();
        $out = [];
        foreach (array_unique(array_merge($codes, $rows)) as $code) {
            $pack = $this->for($code);
            if (! $pack['enabled']) {
                continue;
            }
            $out[] = ['code' => $code, 'source' => $pack['source']] + array_intersect_key($pack, array_flip(self::SCALARS));
        }
        usort($out, fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        return $out;
    }

    public function isEnabled(string $code): bool
    {
        return $this->for($code)['enabled'];
    }

    public function addressRules(string $code): array
    {
        return $this->for($code)['address_format'];
    }

    /** @return array<int,array> identity types; pass kind 'work_authorization' for visas and permits, null for all. */
    public function identifierTypes(string $code, ?string $kind = 'identity'): array
    {
        $types = $this->for($code)['identifier_types'];
        if ($kind === null) {
            return $types;
        }

        return array_values(array_filter($types, fn ($t) => ($t['kind'] ?? 'identity') === $kind));
    }

    public function identifierType(string $code, string $key, string $kind = 'identity'): ?array
    {
        foreach ($this->identifierTypes($code, $kind) as $type) {
            if ($type['key'] === $key) {
                return $type;
            }
        }

        return null;
    }

    public function bankSchema(string $code): array
    {
        return $this->for($code)['bank_schema'];
    }

    /** Field-level problems for an address in the given country: ['postal_code' => 'message', 'extra.district' => '...']. */
    public function addressErrors(string $code, array $address): array
    {
        $pack = $this->for($code);
        $errors = [];
        foreach ($pack['address_format'] as $field) {
            $key = $field['key'];
            $isColumn = in_array($key, self::ADDRESS_COLUMNS, true);
            $path = $isColumn ? $key : 'extra.'.$key;
            $value = $isColumn ? ($address[$key] ?? null) : (($address['extra'] ?? [])[$key] ?? null);
            $label = $field['label'];
            $required = ! empty($field['required']) || $key === 'line1'; // line1 is mandatory everywhere (schema)
            if ($value === null || (is_string($value) && trim($value) === '')) {
                if ($required) {
                    $errors[$path] = "$label is required for addresses in {$pack['name']}.";
                }

                continue;
            }
            if (! is_scalar($value)) {
                $errors[$path] = "$label is not valid.";

                continue;
            }
            $value = $this->normalizeAddressValue($key, trim((string) $value));
            if (mb_strlen($value) > ($key === 'line1' || $key === 'line2' ? 255 : 120)) {
                $errors[$path] = "$label is too long.";

                continue;
            }
            if (! empty($field['options'])) {
                if ($this->matchOption($field['options'], $value) === null) {
                    $errors[$path] = "Choose a valid $label for {$pack['name']}.";
                }

                continue;
            }
            if (! empty($field['pattern']) && ! self::matches($field['pattern'], $value)) {
                $errors[$path] = $field['hint'] ?? "$label does not look right for {$pack['name']}.";
            }
        }

        return $errors;
    }

    /**
     * Validates an address against the country rules and returns it normalized:
     * ['line1','line2','city','region','postal_code','extra'=>[...pack-specific parts]].
     *
     * @throws ValidationException with friendly per-field messages
     */
    public function validateAddress(string $code, array $address): array
    {
        $errors = $this->addressErrors($code, $address);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $pack = $this->for($code);
        $out = ['line1' => null, 'line2' => null, 'city' => null, 'region' => null, 'postal_code' => null, 'extra' => []];
        foreach ($pack['address_format'] as $field) {
            $key = $field['key'];
            $isColumn = in_array($key, self::ADDRESS_COLUMNS, true);
            $value = $isColumn ? ($address[$key] ?? null) : (($address['extra'] ?? [])[$key] ?? null);
            $value = is_scalar($value) && trim((string) $value) !== '' ? $this->normalizeAddressValue($key, trim((string) $value)) : null;
            if ($value !== null && ! empty($field['options'])) {
                $value = $this->matchOption($field['options'], $value);
            }
            $isColumn ? $out[$key] = $value : ($value !== null ? $out['extra'][$key] = $value : null);
        }
        $out['extra'] = $out['extra'] ?: null;

        return $out;
    }

    /** Builds the human-readable address lines for display, in pack order. */
    public function formatAddress(string $code, array $address): string
    {
        $parts = [];
        foreach ($this->addressRules($code) as $field) {
            $key = $field['key'];
            $value = in_array($key, self::ADDRESS_COLUMNS, true) ? ($address[$key] ?? null) : (($address['extra'] ?? [])[$key] ?? null);
            if ($value !== null && $value !== '') {
                $parts[] = $value;
            }
        }

        return implode(', ', $parts);
    }

    /** Validates a structured pack payload (country overrides). Returns friendly messages keyed by path. */
    public static function structureErrors(array $payload): array
    {
        $errors = [];
        foreach (self::LISTS as $list) {
            if (! array_key_exists($list, $payload) || $payload[$list] === null) {
                continue;
            }
            if (! is_array($payload[$list]) || ! array_is_list($payload[$list])) {
                $errors[$list] = 'This must be a list of entries.';

                continue;
            }
            $seen = [];
            foreach ($payload[$list] as $i => $entry) {
                $at = "$list.$i";
                if (! is_array($entry)) {
                    $errors[$at] = 'Each entry must be an object.';

                    continue;
                }
                $key = $entry['key'] ?? null;
                if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                    $errors["$at.key"] = 'Each entry needs a key of lower-case letters, digits and underscores (for example "tax_id").';

                    continue;
                }
                if (isset($seen[$key])) {
                    $errors["$at.key"] = "The key \"$key\" is used twice.";
                }
                $seen[$key] = true;
                if (! empty($entry['disabled'])) {
                    continue;
                }
                if (! isset($entry['label']) || ! is_string($entry['label']) || trim($entry['label']) === '' || mb_strlen($entry['label']) > 120) {
                    $errors["$at.label"] = 'Each entry needs a label of up to 120 characters.';
                }
                if (isset($entry['pattern']) && (! is_string($entry['pattern']) || ! self::patternIsValid($entry['pattern']))) {
                    $errors["$at.pattern"] = 'The pattern is not a valid regular expression (max 300 characters, no delimiters).';
                }
                if (isset($entry['required']) && ! is_bool($entry['required'])) {
                    $errors["$at.required"] = 'Required must be true or false.';
                }
                if ($list === 'identifier_types') {
                    if (isset($entry['classification']) && ! \App\Domain\Access\Classification::valid((string) $entry['classification'])) {
                        $errors["$at.classification"] = 'Choose a valid data classification.';
                    }
                    if (isset($entry['mask']) && ! in_array($entry['mask'], ['last4', 'last2', 'full'], true)) {
                        $errors["$at.mask"] = 'Mask must be last4, last2 or full.';
                    }
                    if (isset($entry['kind']) && ! in_array($entry['kind'], ['identity', 'work_authorization'], true)) {
                        $errors["$at.kind"] = 'Kind must be identity or work_authorization.';
                    }
                }
                if ($list === 'bank_schema' && isset($entry['normalize']) && ! in_array($entry['normalize'], ['upper', 'digits', 'trim'], true)) {
                    $errors["$at.normalize"] = 'Normalize must be upper, digits or trim.';
                }
                if (isset($entry['checksum']) && ! in_array($entry['checksum'], ['luhn', 'iban'], true)) {
                    $errors["$at.checksum"] = 'Checksum must be luhn or iban.';
                }
                if (isset($entry['options']) && ! is_array($entry['options'])) {
                    $errors["$at.options"] = 'Options must be a list or a value-to-label map.';
                }
            }
        }

        return $errors;
    }

    private function mergeList(array $base, ?array $override): array
    {
        $out = [];
        foreach ($base as $item) {
            if (isset($item['key'])) {
                $out[$item['key']] = $item;
            }
        }
        foreach ($override ?? [] as $item) {
            if (! is_array($item) || ! isset($item['key'])) {
                continue;
            }
            if (! empty($item['disabled'])) {
                unset($out[$item['key']]);

                continue;
            }
            $out[$item['key']] = $item;
        }

        return array_values($out);
    }

    /** Normalizes options to [{value,label}] and fills defaults. */
    private function normalizeField(array $field): array
    {
        $field['required'] = (bool) ($field['required'] ?? false);
        if (isset($field['options'])) {
            $options = [];
            foreach ((array) $field['options'] as $k => $v) {
                $options[] = is_array($v) ? ['value' => (string) $v['value'], 'label' => (string) ($v['label'] ?? $v['value'])] : ['value' => (string) $k, 'label' => (string) $v];
            }
            $field['options'] = $options;
        }

        return $field;
    }

    private function normalizeAddressValue(string $key, string $value): string
    {
        return $key === 'postal_code' ? mb_strtoupper($value) : $value;
    }

    /** Returns the canonical option value for a submitted value or label, or null. */
    private function matchOption(array $options, string $value): ?string
    {
        foreach ($options as $option) {
            if (strcasecmp($option['value'], $value) === 0 || strcasecmp($option['label'], $value) === 0) {
                return $option['value'];
            }
        }

        return null;
    }
}
