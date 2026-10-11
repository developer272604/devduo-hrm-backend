<?php

namespace App\Domain\Security;

/** RFC 4226 HOTP / RFC 6238 TOTP implemented with hash_hmac only (no third-party dependency). */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function base32Encode(string $binary): string
    {
        $bits = '';
        foreach (str_split($binary) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    public static function hotp(string $binaryKey, int $counter, int $digits = 6, string $algorithm = 'sha1'): string
    {
        $hash = hash_hmac($algorithm, pack('J', $counter), $binaryKey, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function step(int $time, int $period = 30): int
    {
        return intdiv($time, $period);
    }

    public static function code(string $binaryKey, int $time, int $digits = 6, string $algorithm = 'sha1', int $period = 30): string
    {
        return self::hotp($binaryKey, self::step($time, $period), $digits, $algorithm);
    }

    /** Returns the matched time step (for replay protection) or null. Accepts +/- $window steps of clock drift. */
    public static function verify(string $base32Secret, string $code, ?int $now = null, int $window = 1, ?int $afterStep = null, int $digits = 6): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.$digits.'}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($base32Secret);
        if ($key === '') {
            return null;
        }
        $current = self::step($now ?? time());
        for ($i = -$window; $i <= $window; $i++) {
            $step = $current + $i;
            if ($afterStep !== null && $step <= $afterStep) {
                continue;
            }
            if (hash_equals(self::hotp($key, $step, $digits), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function uri(string $issuer, string $account, string $secret): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }
}
