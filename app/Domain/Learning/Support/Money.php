<?php

namespace App\Domain\Learning\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Decimal-safe money helpers (2 dp, half-up). Floats from JSON/SQLite are formatted, never used in arithmetic. */
final class Money
{
    public static function d(string|int|float|null $v): BigDecimal
    {
        if ($v === null || $v === '') return BigDecimal::zero();

        return BigDecimal::of(is_float($v) ? number_format($v, 4, '.', '') : (string) $v);
    }

    public static function in(string|int|float|null $v): string
    {
        return (string) self::d($v)->toScale(2, RoundingMode::HALF_UP);
    }

    public static function add(string|int|float|null $a, string|int|float|null $b): string
    {
        return (string) self::d($a)->plus(self::d($b))->toScale(2, RoundingMode::HALF_UP);
    }

    public static function sum(iterable $values): string
    {
        $t = BigDecimal::zero();
        foreach ($values as $v) $t = $t->plus(self::d($v));

        return (string) $t->toScale(2, RoundingMode::HALF_UP);
    }

    public static function gt(string|int|float|null $a, string|int|float|null $b): bool
    {
        return self::d($a)->isGreaterThan(self::d($b));
    }

    /** a / b * 100 as a one-decimal string; 0 when b is 0. */
    public static function pct(int|string $a, int|string $b): string
    {
        return (float) $b == 0 ? '0.0' : (string) BigDecimal::of((string) $a)->multipliedBy(100)->dividedBy((string) $b, 1, RoundingMode::HALF_UP);
    }
}
