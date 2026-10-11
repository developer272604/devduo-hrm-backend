<?php
namespace App\Domain\Operations;
use Illuminate\Validation\ValidationException;

final class Money {
    public const SCALES = ['USD' => 2, 'EUR' => 2, 'GBP' => 2, 'INR' => 2, 'JPY' => 0, 'KWD' => 3];
    public static function scale(string $currency): int {
        if (! array_key_exists($currency, self::SCALES)) throw ValidationException::withMessages(['currency' => 'Choose a supported currency.']);
        return self::SCALES[$currency];
    }
    public static function minor(string $value, int $scale, string $field = 'amount'): int {
        $pattern = $scale ? '/^\d{1,9}(?:\.\d{1,'.$scale.'})?$/' : '/^\d{1,9}$/';
        if (! preg_match($pattern, $value)) throw ValidationException::withMessages([$field => 'Enter a positive decimal amount with at most '.$scale.' decimal places.']);
        [$whole, $fraction] = array_pad(explode('.', $value), 2, '');
        return ((int) $whole * (10 ** $scale)) + (int) str_pad($fraction, $scale, '0');
    }
    public static function format(int $minor, int $scale): string {
        if (! $scale) return (string) $minor;
        return intdiv($minor, 10 ** $scale).'.'.str_pad((string) ($minor % (10 ** $scale)), $scale, '0', STR_PAD_LEFT);
    }
}
