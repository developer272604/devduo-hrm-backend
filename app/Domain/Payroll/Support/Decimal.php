<?php

namespace App\Domain\Payroll\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * Decimal helper for payroll quantities (days, hours, rates, percentages) and intermediate money maths.
 * Values are DECIMAL STRINGS or Brick BigDecimal. Floats are REJECTED (never silently converted): binary floating point
 * has no place in payroll. Integers are accepted. All arithmetic is exact; rounding is explicit through Rounding.
 */
final class Decimal
{
    public static function of(string|int|float|BigNumber|null $value): BigDecimal
    {
        self::guard($value);
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if ($value instanceof BigNumber) {
            return $value->toBigDecimal();
        }
        $text = trim((string) $value);
        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $text)) {
            throw new InvalidArgumentException('Not a decimal number: '.$text);
        }

        return BigDecimal::of($text);
    }

    /**
     * Reads a decimal column fetched with DB::table() (the SQLite test driver returns int/float; MySQL returns strings). The float branch is
     * a driver artefact only: the value is formatted to the column scale, never used in arithmetic.
     */
    public static function fromDb(string|int|float|null $value, int $scale): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value)) {
            return number_format($value, $scale, '.', '');
        }

        return self::str(self::of($value), $scale);
    }

    /** Floats are an error, with a message that names the problem. */
    public static function guard(mixed $value): void
    {
        if (is_float($value)) {
            throw new InvalidArgumentException('Floating point values are not allowed in payroll; pass a decimal string.');
        }
    }

    public static function add(string|int|BigNumber $a, string|int|BigNumber $b): BigDecimal
    {
        return self::of($a)->plus(self::of($b));
    }

    public static function sub(string|int|BigNumber $a, string|int|BigNumber $b): BigDecimal
    {
        return self::of($a)->minus(self::of($b));
    }

    public static function mul(string|int|BigNumber $a, string|int|BigNumber $b): BigDecimal
    {
        return self::of($a)->multipliedBy(self::of($b));
    }

    /** Exact division rounded to $scale with the given mode (division is the only place precision can be lost, so it is explicit). */
    public static function div(string|int|BigNumber $a, string|int|BigNumber $b, int $scale = 6, string $mode = Rounding::HALF_UP): BigDecimal
    {
        $divisor = self::of($b);
        if ($divisor->isZero()) {
            throw new InvalidArgumentException('Division by zero.');
        }
        try {
            return self::of($a)->dividedBy($divisor, $scale, Rounding::brick($mode));
        } catch (MathException $e) {
            throw new InvalidArgumentException('Division failed: '.$e->getMessage());
        }
    }

    public static function cmp(string|int|BigNumber $a, string|int|BigNumber $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function eq(string|int|BigNumber $a, string|int|BigNumber $b): bool
    {
        return self::cmp($a, $b) === 0;
    }

    public static function min(string|int|BigNumber $a, string|int|BigNumber $b): BigDecimal
    {
        return self::cmp($a, $b) <= 0 ? self::of($a) : self::of($b);
    }

    public static function max(string|int|BigNumber $a, string|int|BigNumber $b): BigDecimal
    {
        return self::cmp($a, $b) >= 0 ? self::of($a) : self::of($b);
    }

    public static function isNegative(string|int|BigNumber $a): bool
    {
        return self::of($a)->isNegative();
    }

    public static function isZero(string|int|BigNumber $a): bool
    {
        return self::of($a)->isZero();
    }

    /** @param iterable<string|int|BigNumber> $values */
    public static function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $value) {
            $total = $total->plus(self::of($value));
        }

        return $total;
    }

    /** Fixed-scale string (no rounding: throws when digits would be lost; round first with Rounding::apply). */
    public static function str(string|int|BigNumber $value, int $scale): string
    {
        try {
            return (string) self::of($value)->toScale($scale);
        } catch (MathException) {
            throw new InvalidArgumentException('Value has more than '.$scale.' decimal places; round it explicitly first.');
        }
    }
}
