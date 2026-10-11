<?php

namespace App\Domain\People\Records;

/**
 * Blind index for identifiers: an HMAC-SHA256 of the normalized number, keyed by the application key and the tenant id.
 * It lets modules detect duplicate identifiers (same passport on two people) without ever decrypting stored values.
 * The same number always yields the same hash inside one tenant and a different hash in another tenant.
 */
final class IdentityHash
{
    /** Upper-cases and strips whitespace, hyphens, dots and slashes so "ab 12-34" and "AB1234" are the same identifier. */
    public static function normalize(string $number): string
    {
        return preg_replace('/[\s\-.\/]+/u', '', mb_strtoupper(trim($number))) ?? '';
    }

    public static function for(string $number, int $tenantId): string
    {
        $key = hash_hmac('sha256', 'hrm-identity-blind-index|tenant:'.$tenantId, (string) config('app.key'), true);

        return hash_hmac('sha256', self::normalize($number), $key);
    }

    /** Last four characters of the normalized number (the only plaintext fragment ever stored). */
    public static function last4(string $number): string
    {
        return mb_substr(self::normalize($number), -4);
    }
}
