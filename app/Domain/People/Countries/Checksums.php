<?php

namespace App\Domain\People\Countries;

/** Optional checksum validators referenced by identifier types and bank fields (luhn, iban). */
final class Checksums
{
    public static function check(string $kind, string $value): bool
    {
        return match ($kind) {
            'luhn' => self::luhn($value),
            'iban' => self::iban($value),
            default => true,
        };
    }

    public static function luhn(string $digits): bool
    {
        if (! ctype_digit($digits)) {
            return false;
        }
        $sum = 0;
        $alternate = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alternate) {
                $n *= 2;
                $n -= $n > 9 ? 9 : 0;
            }
            $sum += $n;
            $alternate = ! $alternate;
        }

        return $sum % 10 === 0;
    }

    /** ISO 13616 mod-97 check. */
    public static function iban(string $iban): bool
    {
        $iban = strtoupper($iban);
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/', $iban)) {
            return false;
        }
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder === 1;
    }
}
