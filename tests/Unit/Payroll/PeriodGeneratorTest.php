<?php

namespace Tests\Unit\Payroll;

use App\Domain\Payroll\Calendars\PeriodGenerator;
use PHPUnit\Framework\TestCase;

class PeriodGeneratorTest extends TestCase
{
    public function test_monthly_handles_leap_year(): void
    {
        $p = (new PeriodGenerator)->generate('monthly', '2028-01-01', '2028-03-31');
        $this->assertCount(3, $p);
        $this->assertSame('2028-02-29', $p[1]['end']);
        $this->assertSame('2026-02-28', (new PeriodGenerator)->generate('monthly', '2026-02-01', '2026-02-28')[0]['end']);
    }

    public function test_semi_monthly_weekly_biweekly_four_weekly_and_custom(): void
    {
        $g = new PeriodGenerator;
        $s = $g->generate('semi_monthly', '2026-02-01', '2026-02-28');
        $this->assertSame([['2026-02-01', '2026-02-15'], ['2026-02-16', '2026-02-28']], array_map(fn ($x) => [$x['start'], $x['end']], $s));
        $w = $g->generate('weekly', '2026-01-05', '2026-01-31', '2025-12-29');
        $this->assertSame('2026-01-05', $w[0]['start']); $this->assertSame('2026-01-11', $w[0]['end']); $this->assertCount(4, $w);
        $b = $g->generate('biweekly', '2026-01-05', '2026-02-28', '2026-01-05');
        $this->assertSame('2026-01-18', $b[0]['end']); $this->assertCount(4, $b);
        $f = $g->generate('four_weekly', '2026-01-01', '2026-03-31', '2026-01-05');
        $this->assertSame('2026-01-05', $f[0]['start']); $this->assertSame('2026-02-01', $f[0]['end']);
        $c = $g->generate('custom', '2026-01-01', '2026-12-31', null, ['ranges' => [['start' => '2026-01-01', 'end' => '2026-01-20'], ['start' => '2026-01-21', 'end' => '2026-03-01']]]);
        $this->assertCount(2, $c);
    }

    public function test_overlapping_custom_ranges_and_missing_anchor_are_rejected(): void
    {
        $g = new PeriodGenerator;
        $this->expectException(\InvalidArgumentException::class);
        $g->generate('custom', '2026-01-01', '2026-12-31', null, ['ranges' => [['start' => '2026-01-01', 'end' => '2026-01-20'], ['start' => '2026-01-10', 'end' => '2026-01-30']]]);
    }

    public function test_anchor_required(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PeriodGenerator)->generate('weekly', '2026-01-01', '2026-02-01');
    }
}
