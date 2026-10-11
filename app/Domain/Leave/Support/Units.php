<?php

namespace App\Domain\Leave\Support;

use InvalidArgumentException;

/**
 * Decimal-safe leave units. Units are strings with exactly 4 decimal places ("1.5000"); NEVER floats.
 * Everything goes through bcmath. Values read back from SQLite (tests) may arrive as int/float, so normalize() accepts those
 * and rounds them to 4 dp via sprintf (safe: ledger values never exceed 14 significant digits).
 */
final class Units
{
    public const SCALE = 4;

    public static function normalize(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }
        if (is_float($value)) {
            $value = sprintf('%.'.self::SCALE.'F', $value);
        }
        $value = trim((string) $value);
        if (! preg_match('/^[+-]?\d+(\.\d+)?$/', $value)) {
            throw new InvalidArgumentException("Not a decimal number: $value");
        }

        return bcadd($value, '0', self::SCALE);
    }

    /** Strict variant for user/API input: more than 4 decimal places is rejected rather than silently truncated. */
    public static function strict(string|int|float $value): string
    {
        $text = is_float($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : trim((string) $value);
        if (! preg_match('/^[+-]?\d+(\.\d+)?$/', $text)) {
            throw new InvalidArgumentException("Not a decimal number: $text");
        }
        if (bccomp(bcadd($text, '0', 12), bcadd($text, '0', self::SCALE), 12) !== 0) {
            throw new InvalidArgumentException("Leave units support at most 4 decimal places: $text");
        }

        return bcadd($text, '0', self::SCALE);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcadd(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return bcsub(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function mul(string|int|float|null $a, string|int|float|null $b): string
    {
        $raw = bcmul(self::normalize($a), self::normalize($b), self::SCALE * 2);

        return self::round($raw);
    }

    /** Divides and rounds half away from zero to 4 dp. */
    public static function div(string|int|float|null $a, string|int|float|null $b): string
    {
        $divisor = self::normalize($b);
        if (bccomp($divisor, '0', self::SCALE) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return self::round(bcdiv(self::normalize($a), $divisor, self::SCALE + 4));
    }

    /** Rounds a longer decimal string half away from zero to 4 dp (bcadd truncates, so add half a unit of the last place first). */
    public static function round(string $value): string
    {
        $half = '0.'.str_repeat('0', self::SCALE).'5';

        return bccomp($value, '0', self::SCALE * 2) >= 0 ? bcadd($value, $half, self::SCALE) : bcsub($value, $half, self::SCALE);
    }

    public static function neg(string|int|float|null $a): string
    {
        return bcmul(self::normalize($a), '-1', self::SCALE);
    }

    public static function abs(string|int|float|null $a): string
    {
        $n = self::normalize($a);

        return bccomp($n, '0', self::SCALE) < 0 ? self::neg($n) : $n;
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return bccomp(self::normalize($a), self::normalize($b), self::SCALE);
    }

    public static function isZero(string|int|float|null $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    public static function isNegative(string|int|float|null $a): bool
    {
        return self::cmp($a, '0') < 0;
    }

    public static function min(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::cmp($a, $b) <= 0 ? self::normalize($a) : self::normalize($b);
    }

    public static function max(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::normalize($a) : self::normalize($b);
    }

    /** @param iterable<string|int|float|null> $values */
    public static function sum(iterable $values): string
    {
        $total = '0.0000';
        foreach ($values as $v) {
            $total = self::add($total, $v);
        }

        return $total;
    }
}
