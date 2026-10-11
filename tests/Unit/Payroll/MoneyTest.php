<?php

namespace Tests\Unit\Payroll;

use App\Domain\Payroll\Support\Checksum;
use App\Domain\Payroll\Support\CurrencyMismatch;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\Money;
use App\Domain\Payroll\Support\Rounding;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_exact_decimal_arithmetic_has_no_binary_float_drift(): void
    {
        $a = Money::of('0.10', 'USD')->plus(Money::of('0.20', 'USD'));
        $this->assertSame('0.30', $a->toString());
        $this->assertTrue($a->equals(Money::of('0.3', 'USD')));
        $this->assertSame('100.01', Money::of('100.01', 'INR')->dividedBy(3)->times(3)->round()->toString());
    }

    public function test_floats_are_rejected_everywhere(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of(0.1, 'USD');
    }

    public function test_decimal_helper_rejects_non_numeric_input_and_guards_floats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::of('12,5');
    }

    public function test_decimal_guard_flags_floats(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Decimal::guard(0.1);
    }

    public function test_currencies_are_never_silently_mixed(): void
    {
        $this->expectException(CurrencyMismatch::class);
        Money::of('1', 'USD')->plus(Money::of('1', 'EUR'));
    }

    public function test_currency_exponents(): void
    {
        $this->assertSame(0, Money::exponent('JPY'));
        $this->assertSame(2, Money::exponent('INR'));
        $this->assertSame(3, Money::exponent('KWD'));
        $this->assertSame('1235', Money::of('1234.5', 'JPY')->round()->toString());
        $this->assertSame('1.235', Money::of('1.2345', 'KWD')->round()->toString());
    }

    #[DataProvider('modes')]
    public function test_rounding_modes(string $value, string $mode, string $expected): void
    {
        $this->assertSame($expected, Rounding::apply($value, 0, $mode));
    }

    public static function modes(): array
    {
        return [
            ['2.5', Rounding::HALF_UP, '3'], ['-2.5', Rounding::HALF_UP, '-3'], ['2.5', Rounding::HALF_EVEN, '2'], ['3.5', Rounding::HALF_EVEN, '4'],
            ['2.5', Rounding::HALF_DOWN, '2'], ['2.1', Rounding::UP, '3'], ['2.9', Rounding::DOWN, '2'], ['-2.1', Rounding::CEILING, '-2'], ['-2.1', Rounding::FLOOR, '-3'],
        ];
    }

    public function test_rounding_to_an_increment(): void
    {
        $this->assertSame('1235', Rounding::apply('1233.4', 0, Rounding::HALF_UP, 5));
        $this->assertSame('1.35', Rounding::apply('1.34', 2, Rounding::HALF_UP, '0.05'));
    }

    public function test_allocation_never_loses_or_invents_a_minor_unit(): void
    {
        $parts = Money::of('100.00', 'USD')->allocate(['a' => 1, 'b' => 1, 'c' => 1]);
        $this->assertSame(['33.34', '33.33', '33.33'], array_map(fn (Money $m) => $m->toString(), array_values($parts)));
        $this->assertSame('100.00', Money::sum($parts, 'USD')->toString());

        $jpy = Money::of('1000', 'JPY')->allocate(['x' => 2, 'y' => 1]);
        $this->assertSame('1000', Money::sum($jpy, 'JPY')->toString());
        $this->assertSame('-0.01', array_values(Money::of('-0.01', 'USD')->allocate(['a' => 1, 'b' => 1]))[0]->toString());
    }

    public function test_unrounded_values_cannot_leak_into_columns(): void
    {
        $this->assertSame('33.3333', Money::of('100', 'USD')->dividedBy(3)->round(Rounding::HALF_UP, 4)->toColumn());
        $this->expectException(InvalidArgumentException::class);
        Money::of('100', 'USD')->dividedBy(3)->toColumn();
    }

    public function test_percent_and_json(): void
    {
        $this->assertSame('1800.00', Money::of('15000', 'INR')->percent('12')->toString());
        $this->assertSame(['amount' => '12.50', 'currency' => 'USD'], Money::of('12.5', 'USD')->jsonSerialize());
    }

    public function test_checksum_is_order_independent_and_refuses_floats(): void
    {
        $this->assertSame(Checksum::of(['b' => 1, 'a' => ['y' => '2', 'x' => '1']]), Checksum::of(['a' => ['x' => '1', 'y' => '2'], 'b' => 1]));
        $this->expectException(InvalidArgumentException::class);
        Checksum::of(['amount' => 1.5]);
    }
}
