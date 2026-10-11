<?php

namespace Tests\Unit\Attendance\Engine;

use PHPUnit\Framework\TestCase;

class BreaksTest extends TestCase
{
    private const LUNCH = ['09:00', '13:00', '14:00', '18:00'];

    public function test_punch_mode_deducts_the_punched_gap(): void
    {
        $r = EngineFixtures::day(self::LUNCH, ['breaks' => ['mode' => 'punch']]);
        $this->assertSame(540, $r->grossMinutes);
        $this->assertSame(60, $r->breakMinutes);
        $this->assertSame(480, $r->netMinutes);
        $this->assertSame('present', $r->status);
    }

    public function test_punch_mode_without_break_punches_deducts_nothing(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], ['breaks' => ['mode' => 'punch']]);
        $this->assertSame(0, $r->breakMinutes);
        $this->assertSame(540, $r->netMinutes);
    }

    public function test_scheduled_paid_break_segment_is_not_deducted(): void
    {
        $r = EngineFixtures::day(self::LUNCH, ['breaks' => ['mode' => 'punch']], ['breaks' => [['13:00', '14:00', true]]]);
        $this->assertSame(0, $r->breakMinutes);
        $this->assertSame(540, $r->netMinutes);
    }

    public function test_only_the_part_beyond_the_paid_break_is_deducted(): void
    {
        $r = EngineFixtures::day(['09:00', '13:00', '14:30', '18:00'], ['breaks' => ['mode' => 'punch']], ['breaks' => [['13:00', '14:00', true]]]);
        $this->assertSame(30, $r->breakMinutes);
    }

    public function test_policy_paid_breaks_are_never_deducted(): void
    {
        $r = EngineFixtures::day(self::LUNCH, ['breaks' => ['mode' => 'punch', 'paid' => true]]);
        $this->assertSame(0, $r->breakMinutes);
        $this->assertSame(540, $r->netMinutes);
    }

    public function test_multiple_breaks_are_summed(): void
    {
        $r = EngineFixtures::day(['09:00', '11:00', '11:15', '13:00', '13:45', '18:00']);
        $this->assertSame(60, $r->breakMinutes);
        $this->assertSame(480, $r->netMinutes);
        $this->assertSame(2, EngineFixtures::step($r, 'breaks')['outcome']['break_count']);
    }

    public function test_excessive_break_minutes_raises_info_exception(): void
    {
        $r = EngineFixtures::day(['09:00', '13:00', '14:30', '18:30'], ['breaks' => ['mode' => 'punch', 'excessive_break_minutes' => 60]]);
        $e = EngineFixtures::exception($r, 'excessive_break');
        $this->assertSame('info', $e['severity']);
        $this->assertSame(90, $e['details']['break_minutes']);
        $this->assertSame('breaks.excessive_break_minutes', $e['rule']);
    }

    public function test_max_breaks_exceeded_raises_excessive_break(): void
    {
        $r = EngineFixtures::day(['09:00', '10:00', '10:10', '12:00', '12:10', '14:00', '14:10', '18:00'], ['breaks' => ['mode' => 'punch', 'max_breaks' => 2]]);
        $e = EngineFixtures::exception($r, 'excessive_break');
        $this->assertSame('breaks.max_breaks', $e['rule']);
        $this->assertSame(3, $e['details']['break_count']);
    }

    public function test_fixed_mode_deducts_fixed_minutes(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], ['breaks' => ['mode' => 'fixed', 'fixed_minutes' => 45]]);
        $this->assertSame(45, $r->breakMinutes);
        $this->assertSame(495, $r->netMinutes);
    }

    public function test_fixed_mode_does_not_double_deduct_when_schedule_has_an_unpaid_break(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], ['breaks' => ['mode' => 'fixed', 'fixed_minutes' => 45]], ['breaks' => [['13:00', '14:00', false]]]);
        $this->assertSame(60, $r->breakMinutes);
        $this->assertSame('scheduled_unpaid_break_segments', EngineFixtures::step($r, 'breaks')['outcome']['method']);
    }

    public function test_fixed_mode_ignores_scheduled_paid_break_segments(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], ['breaks' => ['mode' => 'fixed', 'fixed_minutes' => 30]], ['breaks' => [['16:00', '16:15', true]]]);
        $this->assertSame(30, $r->breakMinutes);
    }

    public function test_fixed_mode_with_no_presence_deducts_nothing(): void
    {
        $r = EngineFixtures::day([], ['breaks' => ['mode' => 'fixed', 'fixed_minutes' => 45]]);
        $this->assertSame(0, $r->breakMinutes);
    }

    public function test_auto_deduct_after_threshold_without_break_punches(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00'], ['breaks' => ['mode' => 'auto_deduct', 'auto_deduct_after_minutes' => 360, 'auto_deduct_minutes' => 30]]);
        $this->assertSame(30, $r->breakMinutes);
        $this->assertSame(510, $r->netMinutes);
    }

    public function test_auto_deduct_not_triggered_below_threshold(): void
    {
        $r = EngineFixtures::day(['09:00', '13:00'], ['breaks' => ['mode' => 'auto_deduct', 'auto_deduct_after_minutes' => 360, 'auto_deduct_minutes' => 30]]);
        $this->assertSame(0, $r->breakMinutes);
    }

    public function test_auto_deduct_uses_punched_breaks_when_present(): void
    {
        $r = EngineFixtures::day(self::LUNCH, ['breaks' => ['mode' => 'auto_deduct', 'auto_deduct_minutes' => 30]]);
        $this->assertSame(60, $r->breakMinutes);
    }

    public function test_none_mode_counts_gaps_as_work(): void
    {
        $r = EngineFixtures::day(self::LUNCH, ['breaks' => ['mode' => 'none']]);
        $this->assertSame(0, $r->breakMinutes);
        $this->assertSame(540, $r->netMinutes);
    }

    public function test_net_is_never_negative(): void
    {
        $r = EngineFixtures::day(['09:00', '09:30'], ['breaks' => ['mode' => 'fixed', 'fixed_minutes' => 120]]);
        $this->assertSame(30, $r->grossMinutes);
        $this->assertSame(30, $r->breakMinutes);
        $this->assertSame(0, $r->netMinutes);
    }

    public function test_break_deduction_can_push_the_day_below_the_full_day(): void
    {
        $r = EngineFixtures::day(['09:00', '13:00', '15:00', '18:00'], ['breaks' => ['mode' => 'punch']]);
        $this->assertSame(420, $r->netMinutes);
        $this->assertSame('half_day', $r->status);
        $this->assertNotNull(EngineFixtures::exception($r, 'insufficient_hours'));
    }
}
