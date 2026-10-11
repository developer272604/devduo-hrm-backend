<?php

namespace App\Domain\Payroll\Support;

use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Explicit rounding for payroll. Countries and tenants choose the mode, scale and optional increment (e.g. round to the
 * nearest 5 or 10); the engine never rounds implicitly. Modes:
 *   half_up     ties away from zero (default; 2.5 -> 3, -2.5 -> -3)
 *   half_down   ties toward zero
 *   half_even   banker's rounding
 *   up          away from zero      down  toward zero
 *   ceiling     toward +infinity    floor toward -infinity
 */
final class Rounding
{
    public const HALF_UP = 'half_up';

    public const HALF_DOWN = 'half_down';

    public const HALF_EVEN = 'half_even';

    public const UP = 'up';

    public const DOWN = 'down';

    public const CEILING = 'ceiling';

    public const FLOOR = 'floor';

    public const MODES = [self::HALF_UP, self::HALF_DOWN, self::HALF_EVEN, self::UP, self::DOWN, self::CEILING, self::FLOOR];

    public static function brick(string $mode): RoundingMode
    {
        return match ($mode) {
            self::HALF_UP => RoundingMode::HALF_UP,
            self::HALF_DOWN => RoundingMode::HALF_DOWN,
            self::HALF_EVEN => RoundingMode::HALF_EVEN,
            self::UP => RoundingMode::UP,
            self::DOWN => RoundingMode::DOWN,
            self::CEILING => RoundingMode::CEILING,
            self::FLOOR => RoundingMode::FLOOR,
            default => throw new InvalidArgumentException('Unknown rounding mode: '.$mode),
        };
    }

    public static function valid(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }

    /**
     * Rounds to $scale decimal places; with $increment (e.g. "5", "0.05") rounds to the nearest multiple of it and then
     * expresses the result at $scale. Returns a fixed-scale decimal STRING.
     */
    public static function apply(string|int|BigNumber $value, int $scale = 2, string $mode = self::HALF_UP, string|int|null $increment = null): string
    {
        if ($scale < 0 || $scale > 12) {
            throw new InvalidArgumentException('Scale must be between 0 and 12.');
        }
        $number = Decimal::of($value);
        if ($increment !== null && $increment !== '') {
            $step = Decimal::of($increment);
            if ($step->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException('Rounding increment must be positive.');
            }
            $units = $number->dividedBy($step, 0, self::brick($mode));
            $number = $units->multipliedBy($step);
        }

        return (string) $number->toScale($scale, self::brick($mode));
    }
}
