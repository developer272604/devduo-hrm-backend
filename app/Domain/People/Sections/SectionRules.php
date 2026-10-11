<?php

namespace App\Domain\People\Sections;

use Closure;

/** Small validation building blocks shared by the profile-section controllers. */
final class SectionRules
{
    /** required on create; on update only validated when present (and then still required, so it cannot be blanked). */
    public static function required(bool $create): array
    {
        return $create ? ['required'] : ['sometimes', 'required'];
    }

    public static function optional(): array
    {
        return ['sometimes', 'nullable'];
    }

    /** Basic phone check: optional leading +, digits and common separators, 6 to 15 digits. */
    public static function phone(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $digits = is_string($value) ? preg_replace('/\D/', '', $value) : '';
            if (! is_string($value) || ! preg_match('/^\+?[0-9][0-9\s().\-]*$/', $value) || strlen($digits) < 6 || strlen($digits) > 15) {
                $fail('Enter a valid phone number, for example +91 98765 43210.');
            }
        };
    }

    /** Small JSON-ish metadata bag: scalar values, short keys, bounded size. */
    public static function metadata(int $maxKeys = 20): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($maxKeys) {
            if (! is_array($value) || count($value) > $maxKeys || strlen((string) json_encode($value)) > 2000) {
                $fail('The benefit details are too large or not in a valid format.');

                return;
            }
            foreach ($value as $key => $item) {
                if (! is_string($key) || strlen($key) > 60 || (! is_scalar($item) && $item !== null)) {
                    $fail('The benefit details must be simple name and value pairs.');

                    return;
                }
            }
        };
    }
}
