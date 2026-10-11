<?php

namespace App\Domain\Performance\Support;

use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Rounding;
use Brick\Math\BigDecimal;

/** Decimal-safe helpers for performance maths. Floats from JSON input or the SQLite driver are formatted, never used in arithmetic. */
final class Num
{
    /** Normalizes request input or a DB value (string|int|float|null) into a decimal string. */
    public static function in(string|int|float|null $v, int $scale = 4): string
    {
        if ($v === null || $v === '') return '0';
        if (is_float($v)) return number_format($v, $scale, '.', '');

        return (string) Decimal::of($v);
    }

    public static function d(string|int|float|null $v): BigDecimal
    {
        return Decimal::of(self::in($v));
    }

    public static function round(string|int|BigDecimal $v, int $scale): string
    {
        return Rounding::apply($v, $scale, Rounding::HALF_UP);
    }

    public static function clamp(BigDecimal $v, string $min, string $max): BigDecimal
    {
        return Decimal::min(Decimal::max($v, $min), $max);
    }

    /** Percentage progress between start and target (direction-agnostic), clamped to 0..100, scale 2. */
    public static function progress(string|int|float|null $start, string|int|float|null $target, string|int|float|null $current): string
    {
        $s = self::d($start); $t = self::d($target); $c = self::d($current);
        $span = $t->minus($s);
        if ($span->isZero()) return Decimal::cmp($c, $t) >= 0 ? '100.00' : '0.00';
        $pct = Decimal::mul(Decimal::div($c->minus($s), $span, 8), 100);

        return self::round(self::clamp($pct, '0', '100'), 2);
    }
}
