<?php

namespace Tests\Unit\Payroll;

use App\Domain\Payroll\Calculation\Formula;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FormulaTest extends TestCase
{
    public function test_exact_decimal_arithmetic_proration_and_rounding(): void
    {
        $f = new Formula;
        $this->assertSame('0.30', $f->calculate('0.1 + 0.2'));
        $this->assertSame('23333.33', $f->calculate('BASE / WORKING_DAYS * PAYABLE_DAYS', ['BASE' => '35000', 'WORKING_DAYS' => '30', 'PAYABLE_DAYS' => '20']));
        $this->assertSame('1800.00', $f->calculate('MIN(BASE, 15000) * 0.12', ['BASE' => '20000']));
        $this->assertSame('-1.01', $f->calculate('-1.005'));
        $this->assertSame('1.00', $f->calculate('1 / 3 * 3'));
        $this->assertSame('100000000000000000.00', $f->calculate('99999999999999999.99 + 0.01'));
    }

    #[DataProvider('invalid')]
    public function test_rejects_unsafe_or_invalid_formulas(string $expression): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Formula)->calculate($expression);
    }

    public static function invalid(): array
    {
        return array_map(fn ($s) => [$s], ['system("whoami")', '1;2', 'file_get_contents(1)', '1 / 0', 'UNDEFINED + 1', '2 ** 4', '(1+2', '1+2)', str_repeat('(', 40).'1'.str_repeat(')', 40)]);
    }

    public function test_rejects_float_inputs_instead_of_silently_rounding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Formula)->calculate('BASE', ['BASE' => 0.1]);
    }

    public function test_extended_grammar(): void
    {
        $f = new Formula;
        $this->assertSame('1200.00', $f->calculate('ROUND(BASE * 12%, 0) * 10', ['BASE' => '1000.4']));
        $this->assertSame('500.00', $f->calculate('IF(BASE >= 10000 AND NOT BASE > 20000, 500, 0)', ['BASE' => '15000']));
        $this->assertSame('0.00', $f->calculate('IF(BASE >= 10000 OR BASE == 1, 0, 5)', ['BASE' => '15000']));
        $this->assertSame('10.00', $f->calculate('CLAMP(BASE, 10, 20)', ['BASE' => '3']));
        $this->assertSame('3.00', $f->calculate('MAX(1, 2, 3)'));
        $this->assertSame('0.34', $f->calculate('ROUNDUP(1 / 3, 2)'));
        $this->assertSame('0.33', $f->calculate('ROUNDDOWN(1 / 3, 2)'));
        $this->assertSame('3.00', $f->calculate('FLOOR(3.9) + ABS(0)'));
    }

    public function test_engine_references_exclude_keywords(): void
    {
        $this->assertSame(['BASE'], (new \App\Domain\Payroll\Calculation\FormulaEngine)->references('IF(BASE > 1 AND NOT BASE < 0, ROUND(BASE), 0)'));
    }
}
