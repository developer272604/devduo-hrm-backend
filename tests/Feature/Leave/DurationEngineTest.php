<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Contracts\Dto\DurationQuery;
use App\Domain\Leave\Contracts\DurationCalculator;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DurationEngineTest extends TestCase
{
    use RefreshDatabase, RequestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLeave();
    }

    private function calc(array $o = [], ?LeaveType $type = null)
    {
        $q = $o + ['start' => '2026-10-12', 'end' => '2026-10-12', 'portion' => 'full_day', 'st' => null, 'et' => null, 'tz' => null];

        return app(DurationCalculator::class)->calculate(new DurationQuery($this->emp->id, ($type ?? $this->annual)->id, $q['start'], $q['end'], $q['portion'], $q['st'], $q['et'], $q['tz'], null, null, '2026-10-07'));
    }

    public function test_weekly_offs_are_not_charged_and_the_charge_is_explained(): void
    {
        $r = $this->calc(['start' => '2026-10-08', 'end' => '2026-10-12']); // Thu..Mon, Sat/Sun off
        $this->assertTrue($r->valid);
        $this->assertSame('3.0000', $r->chargedUnits);
        $this->assertSame('5.0000', $r->calendarUnits);
        $kinds = collect($r->segments)->pluck('dayKind', 'date')->all();
        $this->assertSame('weekly_off', $kinds['2026-10-10']);
        $this->assertStringContainsString('Weekly off', collect($r->segments)->firstWhere('date', '2026-10-10')->explanation);
        $this->assertNotEmpty($r->explanation);
    }

    public function test_sandwich_rule_counts_weekends_between_leave_days_and_against_neighbouring_leave(): void
    {
        $this->policy(['sandwich' => ['enabled' => true, 'count_weekly_offs' => true, 'count_holidays' => true]]);
        $r = $this->calc(['start' => '2026-10-08', 'end' => '2026-10-12']);
        $this->assertSame('5.0000', $r->chargedUnits);
        $this->assertSame('sandwiched', collect($r->segments)->firstWhere('date', '2026-10-10')->dayKind);

        $this->policy(['sandwich' => ['enabled' => true, 'count_weekly_offs' => false]]);
        $this->assertSame('3.0000', $this->calc(['start' => '2026-10-08', 'end' => '2026-10-12'])->chargedUnits);
    }

    public function test_half_quarter_and_hourly_portions_and_allowed_portions(): void
    {
        $this->assertSame('0.5000', $this->calc(['portion' => 'first_half'])->chargedUnits);
        $day = LeaveType::create(['name' => 'Whole days', 'code' => 'WD', 'unit' => 'day']);
        $r = $this->calc(['portion' => 'first_half'], $day);
        $this->assertFalse($r->valid);
        $this->assertStringContainsString('can only be requested', $r->errors[0]);
        $hr = LeaveType::create(['name' => 'Permission', 'code' => 'PM', 'unit' => 'hour']);
        $h = $this->calc(['portion' => 'hours', 'st' => '10:00', 'et' => '12:30'], $hr);
        $this->assertTrue($h->valid);
        $this->assertSame('2.5000', $h->chargedUnits);
        $this->assertSame('150', (string) $h->segments[0]->minutes);
        $this->assertSame('0.2500', $this->calc(['portion' => 'quarter'], LeaveType::create(['name' => 'Q', 'code' => 'Q', 'unit' => 'quarter_day']))->chargedUnits);
    }

    public function test_cross_midnight_and_dst_use_real_elapsed_time(): void
    {
        $t = LeaveType::create(['name' => 'Calendar hours', 'code' => 'CH', 'unit' => 'hour', 'config' => ['calendar_days' => true]]);
        $cross = $this->calc(['start' => '2026-10-24', 'end' => '2026-10-24', 'portion' => 'hours', 'st' => '22:00', 'et' => '02:00', 'tz' => 'Europe/London'], $t);
        $this->assertTrue($cross->valid);
        $this->assertCount(2, $cross->segments);
        $this->assertSame('5.0000', $cross->chargedUnits);
        // London clocks go back at 02:00 BST on 2026-10-25: 22:00 -> 02:00 is FIVE real hours, 00:00 -> 03:00 is FOUR
        $dst = $this->calc(['start' => '2026-10-25', 'end' => '2026-10-25', 'portion' => 'hours', 'st' => '00:00', 'et' => '03:00', 'tz' => 'Europe/London'], $t);
        $this->assertSame('4.0000', $dst->chargedUnits);
        $this->assertContains('clock_change', array_column($dst->warnings, 'kind'));
    }

    public function test_limits_notice_backdate_and_statutory_leave_is_flagged_not_denied(): void
    {
        $this->policy(['limits' => ['max_units' => '2', 'max_consecutive' => 2], 'notice_days' => 10]);
        $r = $this->calc(['start' => '2026-10-12', 'end' => '2026-10-16']);
        $this->assertFalse($r->valid);
        $this->assertGreaterThanOrEqual(2, count($r->errors));
        $this->assertTrue(collect($r->errors)->contains(fn ($e) => str_contains($e, 'notice')));

        $statutory = LeaveType::create(['name' => 'Maternity', 'code' => 'MT', 'kind' => 'maternity', 'category' => 'statutory', 'is_statutory' => true, 'unit' => 'day']);
        $s = $this->calc(['start' => '2026-10-12', 'end' => '2026-10-16'], $statutory);
        $this->assertTrue($s->valid, 'statutory limits become escalations, not refusals');
        $this->assertContains('escalate', array_column($s->warnings, 'severity'));

        $this->policy([]);
        $old = $this->calc(['start' => '2026-09-01', 'end' => '2026-09-01']);
        $this->assertFalse($old->valid);
        $this->assertStringContainsString('after the fact', $old->errors[0]);
    }

    public function test_nothing_to_charge_and_unknown_policy_grant_are_errors(): void
    {
        $r = $this->calc(['start' => '2026-10-10', 'end' => '2026-10-11']);
        $this->assertFalse($r->valid);
        $this->assertStringContainsString('Nothing to request', $r->errors[0]);
        $this->app->instance(\App\Domain\Leave\Contracts\LeavePolicyResolver::class, new class implements \App\Domain\Leave\Contracts\LeavePolicyResolver
        {
            public function resolve(int $employeeId, string $date): ?array
            {
                return null;
            }

            public function rulesFor(int $employeeId, int $leaveTypeId, string $date): ?array
            {
                return null;
            }
        });
        $this->assertStringContainsString('not granted', $this->calc()->errors[0]);
    }
}
