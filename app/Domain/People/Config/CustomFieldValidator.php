<?php

namespace App\Domain\People\Config;

use App\Domain\People\Models\CustomFieldDefinition;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Validates and normalizes custom field values per field type, and validates the "validation" JSON of a
 * definition itself (including a guard against unsafe or catastrophic regular expressions).
 * Values are only ever stored as JSON text in custom_field_values; no schema is ever manipulated.
 */
final class CustomFieldValidator
{
    public const TYPES = ['text', 'long_text', 'number', 'currency', 'date', 'datetime', 'boolean', 'single_select', 'multi_select', 'email', 'phone', 'url',
        'employee_ref', 'department_ref', 'location_ref', 'country', 'attachment_ref'];

    private const TEXT_CAPS = ['text' => 2000, 'long_text' => 20000];

    /** Keys accepted in a definition's validation JSON, per type. */
    private const RULE_KEYS = [
        'text' => ['min_length', 'max_length', 'pattern'], 'long_text' => ['min_length', 'max_length', 'pattern'],
        'email' => ['max_length'], 'phone' => ['max_length'], 'url' => ['max_length'],
        'number' => ['min', 'max', 'decimals'], 'currency' => ['min', 'max', 'decimals', 'currency'],
        'date' => ['min', 'max'], 'datetime' => ['min', 'max'], 'multi_select' => ['min', 'max'],
    ];

    public const MAX_PATTERN_LENGTH = 200;

    // ------------------------------------------------------------------ definition-level validation

    /** Validates the "validation" JSON of a definition. Returns an error message, or null when acceptable. */
    public static function rulesError(string $type, mixed $validation): ?string
    {
        if ($validation === null || $validation === []) {
            return null;
        }
        if (! is_array($validation) || array_is_list($validation)) {
            return 'Validation rules must be an object such as {"max_length": 100}.';
        }
        $allowed = self::RULE_KEYS[$type] ?? [];
        if ($unknown = array_diff(array_keys($validation), $allowed)) {
            return 'Validation rule(s) '.implode(', ', $unknown).' do not apply to '.str_replace('_', ' ', $type).' fields.'.($allowed ? ' Allowed: '.implode(', ', $allowed).'.' : ' This type has no validation rules.');
        }
        foreach (['min_length', 'max_length'] as $key) {
            if (isset($validation[$key]) && (! is_int($validation[$key]) || $validation[$key] < 0 || $validation[$key] > (self::TEXT_CAPS[$type] ?? 2000))) {
                return "$key must be a whole number between 0 and ".(self::TEXT_CAPS[$type] ?? 2000).'.';
            }
        }
        if (isset($validation['min_length'], $validation['max_length']) && $validation['min_length'] > $validation['max_length']) {
            return 'min_length cannot be greater than max_length.';
        }
        if (isset($validation['pattern'])) {
            if (! is_string($validation['pattern']) || ($error = self::regexError($validation['pattern']))) {
                return $error ?? 'pattern must be text.';
            }
        }
        if (in_array($type, ['number', 'currency'], true)) {
            foreach (['min', 'max'] as $key) {
                if (isset($validation[$key]) && (! is_numeric($validation[$key]) || abs((float) $validation[$key]) > 1e15)) {
                    return "$key must be a number.";
                }
            }
            if (isset($validation['decimals']) && (! is_int($validation['decimals']) || $validation['decimals'] < 0 || $validation['decimals'] > 6)) {
                return 'decimals must be a whole number between 0 and 6.';
            }
            if (isset($validation['currency']) && (! is_string($validation['currency']) || ! preg_match('/^[A-Z]{3}$/', $validation['currency']))) {
                return 'currency must be a three-letter ISO code such as USD.';
            }
        }
        if (in_array($type, ['date', 'datetime'], true)) {
            foreach (['min', 'max'] as $key) {
                if (isset($validation[$key]) && (! is_string($validation[$key]) || self::parseDate($validation[$key], $type) === null)) {
                    return "$key must be a valid ".($type === 'date' ? 'Y-m-d date.' : 'date and time.');
                }
            }
        }
        if ($type === 'multi_select') {
            foreach (['min', 'max'] as $key) {
                if (isset($validation[$key]) && (! is_int($validation[$key]) || $validation[$key] < 0 || $validation[$key] > 100)) {
                    return "$key must be a whole number between 0 and 100.";
                }
            }
        }
        if (isset($validation['min'], $validation['max']) && in_array($type, ['number', 'currency', 'multi_select'], true) && (float) $validation['min'] > (float) $validation['max']) {
            return 'min cannot be greater than max.';
        }
        if (isset($validation['min'], $validation['max']) && in_array($type, ['date', 'datetime'], true) && self::parseDate($validation['min'], $type) > self::parseDate($validation['max'], $type)) {
            return 'min cannot be later than max.';
        }

        return null;
    }

    /** Pattern safety: length cap, compiles, and none of the constructs that enable catastrophic backtracking or engine tricks. */
    public static function regexError(string $pattern): ?string
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            return 'A pattern must be 1 to '.self::MAX_PATTERN_LENGTH.' characters long.';
        }
        if (preg_match('/\\\\[1-9gk]|\(\?(?:<|P|&|R|\(|\||\{|C|\d)|\(\*/', $pattern)) {
            return 'This pattern uses advanced regular-expression features (backreferences, lookbehind, recursion or verbs) that are not allowed.';
        }
        if (preg_match('/\((?:[^()\\\\]|\\\\.)*[+*}](?:[^()\\\\]|\\\\.)*\)\s*(?:[+*]|\{\d*,)/', $pattern)) {
            return 'This pattern repeats a repeated group (for example (a+)+) which can freeze the system. Simplify it.';
        }
        if (@preg_match(self::delimited($pattern), '') === false) {
            return 'This is not a valid regular expression.';
        }

        return null;
    }

    private static function delimited(string $pattern): string
    {
        return '~'.preg_replace('/(?<!\\\\)~/', '\~', $pattern).'~u';
    }

    /** Runs a vetted pattern against a value with a tight backtracking budget; failures count as "no match". */
    public static function matches(string $pattern, string $subject): bool
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '50000');
        try {
            return @preg_match(self::delimited($pattern), $subject) === 1;
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }
    }

    // ------------------------------------------------------------------ value validation

    /**
     * @return array{0:mixed,1:?string} [normalized value (null = empty), error message or null]
     */
    public function normalize(CustomFieldDefinition $def, mixed $value, ?Employee $subject = null): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [null, null];
        }
        $rules = $def->validation ?? [];
        $type = $def->type;
        if (! in_array($type, ['multi_select'], true) && is_array($value)) {
            return [null, 'Enter a single value.'];
        }

        return match ($type) {
            'text', 'long_text' => $this->text($type, $value, $rules),
            'number' => $this->number($value, $rules, $rules['decimals'] ?? null),
            'currency' => $this->number($value, $rules, $rules['decimals'] ?? 2),
            'date', 'datetime' => $this->date($type, $value, $rules),
            'boolean' => $this->boolean($value),
            'single_select' => $this->single($def, $value),
            'multi_select' => $this->multi($def, $value, $rules),
            'email' => $this->email($value, $rules),
            'phone' => $this->phone($value, $rules),
            'url' => $this->url($value, $rules),
            'employee_ref' => $this->employeeRef($value),
            'department_ref' => $this->orgRef('departments', $value, 'department'),
            'location_ref' => $this->orgRef('locations', $value, 'location'),
            'country' => $this->country($value),
            'attachment_ref' => $this->attachment($value, $subject),
            default => [null, 'This field has an unsupported type.'],
        };
    }

    /** Human-readable form of a stored value (select labels, referenced names) for display. */
    public function display(CustomFieldDefinition $def, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $tenant = app(TenantContext::class)->id;

        return match ($def->type) {
            'single_select' => $def->options->firstWhere('value', $value)?->label ?? $value,
            'multi_select' => array_map(fn ($v) => $def->options->firstWhere('value', $v)?->label ?? $v, (array) $value),
            'employee_ref' => DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $tenant)->where('employees.uuid', $value)->value('people.display_name'),
            'department_ref' => DB::table('departments')->where('tenant_id', $tenant)->where('id', $value)->value('name'),
            'location_ref' => DB::table('locations')->where('tenant_id', $tenant)->where('id', $value)->value('name'),
            'attachment_ref' => DB::table('employee_documents')->where('tenant_id', $tenant)->where('uuid', $value)->value('title'),
            default => null,
        };
    }

    private function text(string $type, mixed $value, array $rules): array
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return [null, 'Enter text.'];
        }
        $text = trim((string) $value);
        if ($text === '') {
            return [null, null];
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
            return [null, 'This value contains characters that are not allowed.'];
        }
        $cap = min($rules['max_length'] ?? self::TEXT_CAPS[$type], self::TEXT_CAPS[$type]);
        $length = mb_strlen($text);
        if ($length > $cap) {
            return [null, "Use at most $cap characters."];
        }
        if (isset($rules['min_length']) && $length < $rules['min_length']) {
            return [null, 'Use at least '.$rules['min_length'].' characters.'];
        }
        if (isset($rules['pattern']) && ! self::matches($rules['pattern'], $text)) {
            return [null, 'This value is not in the expected format.'];
        }

        return [$text, null];
    }

    private function number(mixed $value, array $rules, ?int $decimals): array
    {
        if (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', trim($value))) {
            $number = str_contains($value, '.') ? (float) trim($value) : (int) trim($value);
        } elseif ((is_int($value) || is_float($value)) && ! is_bool($value)) {
            $number = $value;
        } else {
            return [null, 'Enter a number.'];
        }
        $float = (float) $number;
        if (! is_finite($float) || abs($float) > 1e15) {
            return [null, 'This number is out of range.'];
        }
        if ($decimals !== null && abs(round($float, $decimals) - $float) > 1e-9) {
            return [null, $decimals === 0 ? 'Enter a whole number.' : "Use at most $decimals decimal places."];
        }
        if (isset($rules['min']) && $float < (float) $rules['min']) {
            return [null, 'Enter a value of at least '.$rules['min'].'.'];
        }
        if (isset($rules['max']) && $float > (float) $rules['max']) {
            return [null, 'Enter a value of at most '.$rules['max'].'.'];
        }
        if ($decimals !== null) {
            return [$decimals === 0 ? (int) round($float) : round($float, $decimals), null];
        }

        return [is_int($number) || $float == floor($float) ? (int) $float : $float, null];
    }

    private static function parseDate(string $value, string $type): ?CarbonImmutable
    {
        if ($type === 'date') {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            $errors = CarbonImmutable::getLastErrors();

            return $date && ! ($errors['warning_count'] ?? 0) && ! ($errors['error_count'] ?? 0) && $date->format('Y-m-d') === $value && $date->year >= 1900 && $date->year <= 2200 ? $date : null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::parse($value, TenantClock::timezone())->utc();
        } catch (\Throwable) {
            return null;
        }

        return $date->year >= 1900 && $date->year <= 2200 ? $date : null;
    }

    private function date(string $type, mixed $value, array $rules): array
    {
        $date = is_string($value) ? self::parseDate(trim($value), $type) : null;
        if (! $date) {
            return [null, $type === 'date' ? 'Enter a valid date as YYYY-MM-DD.' : 'Enter a valid date and time, for example 2026-10-07T09:30:00Z.'];
        }
        foreach (['min' => '<', 'max' => '>'] as $key => $bad) {
            if (isset($rules[$key])) {
                $limit = self::parseDate($rules[$key], $type);
                if ($limit && (($bad === '<' && $date < $limit) || ($bad === '>' && $date > $limit))) {
                    return [null, ($key === 'min' ? 'Choose a date on or after ' : 'Choose a date on or before ').$rules[$key].'.'];
                }
            }
        }

        return [$type === 'date' ? $date->format('Y-m-d') : $date->format('Y-m-d\TH:i:s\Z'), null];
    }

    private function boolean(mixed $value): array
    {
        $normalized = match (true) {
            is_bool($value) => $value,
            in_array($value, [1, '1', 'true', 'TRUE', 'True'], true) => true,
            in_array($value, [0, '0', 'false', 'FALSE', 'False'], true) => false,
            default => null,
        };

        return $normalized === null ? [null, 'Choose yes or no.'] : [$normalized, null];
    }

    private function optionValues(CustomFieldDefinition $def): array
    {
        return $def->options->where('active', true)->pluck('value')->all();
    }

    private function single(CustomFieldDefinition $def, mixed $value): array
    {
        $value = is_scalar($value) ? (string) $value : null;

        return $value !== null && in_array($value, $this->optionValues($def), true) ? [$value, null] : [null, 'Choose one of the available options.'];
    }

    private function multi(CustomFieldDefinition $def, mixed $value, array $rules): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [null, 'Choose one or more of the available options.'];
        }
        $values = [];
        foreach ($value as $item) {
            if (! is_scalar($item) || ! in_array((string) $item, $this->optionValues($def), true)) {
                return [null, 'Choose only from the available options.'];
            }
            $values[] = (string) $item;
        }
        $values = array_values(array_unique($values));
        if (isset($rules['min']) && count($values) < $rules['min']) {
            return [null, 'Choose at least '.$rules['min'].' option(s).'];
        }
        if (isset($rules['max']) && count($values) > $rules['max']) {
            return [null, 'Choose at most '.$rules['max'].' option(s).'];
        }

        return [$values, null];
    }

    private function email(mixed $value, array $rules): array
    {
        $email = is_string($value) ? mb_strtolower(trim($value)) : '';
        if ($email === '' || mb_strlen($email) > min($rules['max_length'] ?? 255, 255) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [null, 'Enter a valid email address.'];
        }

        return [$email, null];
    }

    private function phone(mixed $value, array $rules): array
    {
        $phone = is_string($value) || is_int($value) ? trim((string) $value) : '';
        $digits = preg_replace('/\D/', '', $phone);
        if (! preg_match('/^\+?[0-9()\-.\s]{5,30}$/', $phone) || strlen($digits) < 5 || strlen($digits) > 15 || strlen($phone) > min($rules['max_length'] ?? 30, 30)) {
            return [null, 'Enter a valid phone number, for example +91 98765 43210.'];
        }

        return [$phone, null];
    }

    private function url(mixed $value, array $rules): array
    {
        $url = is_string($value) ? trim($value) : '';
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($url === '' || mb_strlen($url) > min($rules['max_length'] ?? 500, 2000) || ! filter_var($url, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
            return [null, 'Enter a valid web address starting with http:// or https://.'];
        }

        return [$url, null];
    }

    private function employeeRef(mixed $value): array
    {
        $uuid = is_string($value) ? strtolower(trim($value)) : '';
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)
            || ! DB::table('employees')->where('tenant_id', app(TenantContext::class)->id)->where('uuid', $uuid)->whereNull('deleted_at')->exists()) {
            return [null, 'Choose an employee from this company.'];
        }

        return [$uuid, null];
    }

    private function orgRef(string $table, mixed $value, string $label): array
    {
        if ((! is_int($value) && ! (is_string($value) && ctype_digit($value))) || ! DB::table($table)->where('tenant_id', app(TenantContext::class)->id)->where('id', (int) $value)->exists()) {
            return [null, "Choose a $label from this company."];
        }

        return [(int) $value, null];
    }

    private function country(mixed $value): array
    {
        return is_string($value) && Countries::valid(trim($value)) ? [strtoupper(trim($value)), null] : [null, 'Choose a valid country (two-letter code).'];
    }

    private function attachment(mixed $value, ?Employee $subject): array
    {
        $uuid = is_string($value) ? strtolower(trim($value)) : '';
        $query = DB::table('employee_documents')->where('tenant_id', app(TenantContext::class)->id)->where('uuid', $uuid)->whereNull('deleted_at');
        if ($subject) {
            $query->where('person_id', $subject->person_id);
        }

        return $uuid !== '' && $query->exists() ? [$uuid, null] : [null, 'Choose one of this employee\'s uploaded documents.'];
    }
}
