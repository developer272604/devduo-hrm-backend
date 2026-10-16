<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Planning\WorkforcePlanner;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Pure model arithmetic: no database. */
class WorkforcePlannerTest extends TestCase
{
    private function plan(array $over = [], array $alt = [], int $scoped = 100): array
    {
        return WorkforcePlanner::plan($over + ['months' => 12, 'annual_attrition_pct' => 0, 'avg_monthly_cost' => 1000, 'currency' => 'INR',
            'alternatives' => [$alt + ['name' => 'Plan A']]], $scoped);
    }

    private function base(array $r, int $alt = 0): array
    {
        return $r['alternatives'][$alt]['cases']['base'];
    }

    public function test_no_attrition_and_no_hiring_keeps_headcount_and_costs_baseline_times_months(): void
    {
        $b = $this->base($this->plan());
        $this->assertEquals(100.0, $b['end_headcount']);
        $this->assertEquals(100 * 1000 * 12, $b['total_cost']);
        $this->assertEquals(0.0, $b['expected_leavers']);
    }

    public function test_twelve_percent_annual_attrition_leaves_88_percent_after_a_year(): void
    {
        $b = $this->base($this->plan(['annual_attrition_pct' => 12]));
        $this->assertEquals(88.0, $b['end_headcount']);
        $this->assertEquals(12.0, $b['expected_leavers']);
    }

    public function test_hires_join_after_the_lead_time_and_cost_starts_then(): void
    {
        $b = $this->base($this->plan([], ['waves' => [['month' => 1, 'count' => 10, 'monthly_cost' => 2000, 'lead_time_months' => 2]]]));
        $s = $b['series'];
        $this->assertEquals(100.0, $s[1]['headcount']); // month 2: still recruiting
        $this->assertEquals(110.0, $s[2]['headcount']); // month 3: joined
        $this->assertEquals(100 * 1000 + 10 * 2000, $s[2]['monthly_cost']);
        $this->assertEquals(110.0, $b['end_headcount']);
    }

    public function test_ramp_makes_effective_capacity_trail_headcount(): void
    {
        $b = $this->base($this->plan([], ['waves' => [['month' => 1, 'count' => 8, 'lead_time_months' => 0, 'ramp_months' => 3]]]));
        $this->assertEquals(102.0, $b['series'][0]['effective_fte']); // 8 x 1/4
        $this->assertEquals(104.0, $b['series'][1]['effective_fte']); // 8 x 2/4
        $this->assertEquals(108.0, $b['series'][3]['effective_fte']); // fully ramped
        $this->assertEquals(108.0, $b['series'][0]['headcount']);
    }

    public function test_instant_backfill_holds_headcount_steady_under_attrition(): void
    {
        $b = $this->base($this->plan(['annual_attrition_pct' => 24], ['backfill' => true, 'backfill_lead_months' => 0]));
        $this->assertEquals(100.0, $b['end_headcount']);
        $this->assertGreaterThan(20, $b['expected_leavers']);
    }

    public function test_delayed_backfill_leaves_a_gap_that_instant_backfill_does_not(): void
    {
        $slow = $this->base($this->plan(['annual_attrition_pct' => 24], ['backfill' => true, 'backfill_lead_months' => 3]));
        $this->assertLessThan(100.0, $slow['end_headcount']);
    }

    public function test_range_runs_low_base_and_high_and_orders_the_outcomes(): void
    {
        $r = $this->plan(['annual_attrition_pct' => 20, 'attrition_low_pct' => 10, 'attrition_high_pct' => 30]);
        $c = $r['alternatives'][0]['cases'];
        $this->assertGreaterThan($c['base']['end_headcount'], $c['low']['end_headcount']);
        $this->assertGreaterThan($c['high']['end_headcount'], $c['base']['end_headcount']);
        $this->assertSame(['low' => 10.0, 'base' => 20.0, 'high' => 30.0], $r['assumptions']['annual_attrition_pct']);
    }

    public function test_alternatives_are_compared_against_the_first_and_budget_breaches_are_flagged(): void
    {
        $r = WorkforcePlanner::plan(['months' => 6, 'annual_attrition_pct' => 0, 'avg_monthly_cost' => 1000, 'monthly_budget' => 105000, 'alternatives' => [
            ['name' => 'Do nothing'],
            ['name' => 'Hire 10', 'waves' => [['month' => 1, 'count' => 10, 'lead_time_months' => 1]]],
        ]], 100);
        $this->assertNull($r['alternatives'][0]['vs_first']);
        $this->assertEquals(10.0, $r['alternatives'][1]['vs_first']['headcount']);
        $this->assertEquals(10 * 1000 * 5, $r['alternatives'][1]['vs_first']['total_cost']);
        $this->assertSame(0, $r['alternatives'][0]['cases']['base']['months_over_budget']);
        $this->assertSame(5, $r['alternatives'][1]['cases']['base']['months_over_budget']);
        $this->assertSame(2, $r['alternatives'][1]['cases']['base']['first_month_over_budget']);
    }

    public function test_without_an_average_cost_no_total_is_invented(): void
    {
        $r = WorkforcePlanner::plan(['months' => 3, 'annual_attrition_pct' => 5, 'alternatives' => [['name' => 'A', 'waves' => [['month' => 1, 'count' => 2, 'monthly_cost' => 500]]]]], 50);
        $b = $r['alternatives'][0]['cases']['base'];
        $this->assertNull($b['total_cost']);
        $this->assertNull($b['series'][0]['monthly_cost']);
        $this->assertSame('working employees inside your data scope', $r['baseline_source']);
        $this->assertSame(50, $r['baseline_headcount']);
    }

    public function test_entered_baseline_overrides_the_scoped_headcount(): void
    {
        $r = $this->plan(['baseline_headcount' => 40]);
        $this->assertSame(40, $r['baseline_headcount']);
        $this->assertSame('entered by you', $r['baseline_source']);
    }

    public function test_invalid_input_is_rejected(): void
    {
        foreach ([['months' => 40], ['annual_attrition_pct' => 120], ['attrition_low_pct' => 30, 'annual_attrition_pct' => 20],
            ['alternatives' => array_fill(0, 5, ['name' => 'x'])], ['alternatives' => [['name' => 'x', 'waves' => [['month' => 0, 'count' => 1]]]]]] as $bad) {
            try {
                $this->plan($bad);
                $this->fail('Expected validation to fail for '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }
}
