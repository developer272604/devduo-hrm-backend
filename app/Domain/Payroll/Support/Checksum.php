<?php

namespace App\Domain\Payroll\Support;

use InvalidArgumentException;

/** Canonical hashing for reproducibility proofs: key order and formatting never change the digest; floats are refused. */
final class Checksum
{
    public static function canonical(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function of(mixed $value): string
    {
        return hash('sha256', self::canonical($value));
    }

    private static function sort(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Floating point values cannot be part of a payroll checksum; use decimal strings.');
        }
        if (! is_array($value)) {
            return $value;
        }
        $out = array_map([self::class, 'sort'], $value);
        if (! array_is_list($out)) {
            ksort($out);
        }

        return $out;
    }
}
