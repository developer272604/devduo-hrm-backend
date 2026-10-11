<?php

namespace App\Domain\Payroll\Support;

use Brick\Math\BigDecimal;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable, currency-tagged, decimal-safe money value.
 *
 *  - Never constructed from a float. Mixing currencies throws CurrencyMismatch (currencies are never silently mixed).
 *  - Intermediate amounts keep up to INTERNAL_SCALE decimals; call round() to settle at the currency's minor-unit exponent
 *    (or a country/tenant rule's scale/mode/increment) - rounding is always explicit.
 *  - allocate() splits an amount in proportion to ratios without losing or inventing a minor unit (largest remainder).
 */
final class Money implements JsonSerializable
{
    public const INTERNAL_SCALE = 6;

    /** ISO 4217 minor-unit exponents that differ from 2. */
    private const EXPONENTS = [
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'ISK' => 0, 'UGX' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0, 'PYG' => 0, 'RWF' => 0, 'DJF' => 0, 'GNF' => 0, 'KMF' => 0, 'VUV' => 0,
        'BHD' => 3, 'KWD' => 3, 'OMR' => 3, 'JOD' => 3, 'TND' => 3, 'IQD' => 3, 'LYD' => 3,
    ];

    private function __construct(private readonly BigDecimal $amount, private readonly string $currency) {}

    public static function of(string|int|float $amount, string $currency): self
    {
        return new self(Decimal::of($amount)->toScale(self::INTERNAL_SCALE, \Brick\Math\RoundingMode::HALF_UP), self::currencyCode($currency));
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[self::currencyCode($currency)] ?? 2;
    }

    public static function currencyCode(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO 4217 code.');
        }

        return $currency;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function plus(self $other): self
    {
        $this->assertSame($other);

        return new self($this->amount->plus($other->amount), $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSame($other);

        return new self($this->amount->minus($other->amount), $this->currency);
    }

    public function negated(): self
    {
        return new self($this->amount->negated(), $this->currency);
    }

    public function abs(): self
    {
        return new self($this->amount->abs(), $this->currency);
    }

    /** Multiplies by a decimal factor (string). The product keeps INTERNAL_SCALE decimals (half up). */
    public function times(string|int $factor): self
    {
        return new self($this->amount->multipliedBy(Decimal::of($factor))->toScale(self::INTERNAL_SCALE, \Brick\Math\RoundingMode::HALF_UP), $this->currency);
    }

    /** Divides by a decimal divisor; the quotient keeps INTERNAL_SCALE decimals (half up). */
    public function dividedBy(string|int $divisor): self
    {
        return new self(Decimal::div($this->amount, $divisor, self::INTERNAL_SCALE), $this->currency);
    }

    /** Percentage of this amount, e.g. percent('12.5'). */
    public function percent(string|int $percent): self
    {
        return $this->times(Decimal::div($percent, 100, 10));
    }

    /** Rounds to the currency exponent (or an explicit scale) with an explicit mode and optional increment. */
    public function round(string $mode = Rounding::HALF_UP, ?int $scale = null, string|int|null $increment = null): self
    {
        $scale ??= self::exponent($this->currency);

        return new self(BigDecimal::of(Rounding::apply($this->amount, $scale, $mode, $increment))->toScale(self::INTERNAL_SCALE), $this->currency);
    }

    public function min(self $other): self
    {
        return $this->compare($other) <= 0 ? $this : $other;
    }

    public function max(self $other): self
    {
        return $this->compare($other) >= 0 ? $this : $other;
    }

    public function compare(self $other): int
    {
        $this->assertSame($other);

        return $this->amount->compareTo($other->amount);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount->compareTo($other->amount) === 0;
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    /**
     * Splits the amount across $ratios (non-negative decimal strings) using largest-remainder, at the currency exponent,
     * so the parts always sum exactly to the rounded total.
     *
     * @param  array<int|string,string|int>  $ratios
     * @return array<int|string,self>
     */
    public function allocate(array $ratios): array
    {
        if ($ratios === []) {
            throw new InvalidArgumentException('At least one ratio is required.');
        }
        $exp = self::exponent($this->currency);
        $total = $this->round(Rounding::HALF_UP, $exp);
        $sum = Decimal::sum($ratios);
        if ($sum->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('Ratios must sum to a positive number.');
        }
        $unit = BigDecimal::of('1')->dividedBy(BigDecimal::of('10')->power($exp), $exp);
        $totalUnits = $total->amount->dividedBy($unit, 0, \Brick\Math\RoundingMode::UNNECESSARY);
        $shares = [];
        $remainders = [];
        $allocated = BigDecimal::zero();
        foreach ($ratios as $key => $ratio) {
            $exact = $totalUnits->abs()->multipliedBy(Decimal::of($ratio))->dividedBy($sum, 12, \Brick\Math\RoundingMode::DOWN);
            $floor = $exact->toScale(0, \Brick\Math\RoundingMode::DOWN);
            $shares[$key] = $floor;
            $remainders[$key] = $exact->minus($floor);
            $allocated = $allocated->plus($floor);
        }
        $left = (int) (string) $totalUnits->abs()->minus($allocated);
        $order = array_keys($remainders);
        usort($order, fn ($a, $b) => $remainders[$b]->compareTo($remainders[$a]) ?: array_search($a, array_keys($remainders), true) <=> array_search($b, array_keys($remainders), true));
        foreach (array_slice($order, 0, $left) as $key) {
            $shares[$key] = $shares[$key]->plus(1);
        }
        $out = [];
        foreach ($shares as $key => $units) {
            $signed = $totalUnits->isNegative() ? $units->negated() : $units;
            $out[$key] = new self($signed->multipliedBy($unit)->toScale(self::INTERNAL_SCALE), $this->currency);
        }

        return $out;
    }

    /** @param array<int,self> $items */
    public static function sum(array $items, string $currency): self
    {
        $total = self::zero($currency);
        foreach ($items as $item) {
            $total = $total->plus($item);
        }

        return $total;
    }

    /** Amount as a decimal string at the currency exponent; throws when the value has not been rounded to it. */
    public function toString(?int $scale = null): string
    {
        return Decimal::str($this->amount, $scale ?? self::exponent($this->currency));
    }

    /** Database/ledger representation (decimal(18,4)); the value must already be rounded to at most 4 decimals. */
    public function toColumn(): string
    {
        return Decimal::str($this->amount, 4);
    }

    public function __toString(): string
    {
        return $this->currency.' '.$this->plain();
    }

    /** @return array{amount:string,currency:string} */
    public function jsonSerialize(): array
    {
        return ['amount' => (string) $this->amount->strippedOfTrailingZeros()->toScale(max(self::exponent($this->currency), $this->amount->strippedOfTrailingZeros()->getScale())), 'currency' => $this->currency];
    }

    /** Exact amount with at least the currency exponent and no needless trailing zeros. */
    private function plain(): string
    {
        $trim = $this->amount->strippedOfTrailingZeros();

        return (string) $trim->toScale(max(self::exponent($this->currency), $trim->getScale()));
    }

    private function assertSame(self $other): void
    {
        if ($other->currency !== $this->currency) {
            throw new CurrencyMismatch('Cannot combine '.$this->currency.' with '.$other->currency.'. Convert explicitly with a recorded exchange rate.');
        }
    }
}
