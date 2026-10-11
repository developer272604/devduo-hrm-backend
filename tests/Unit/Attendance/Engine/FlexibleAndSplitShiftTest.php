<?php

namespace Tests\Unit\Attendance\Engine;

use PHPUnit\Framework\TestCase;

class FlexibleAndSplitShiftTest extends TestCase
{
    private function flex(array $punchSpecs, array $rules = [], array $sched = [], array $o = [])
    {
        $base = [
            'shiftKind' => 'flexible', 'start' => '08:00', 'end' => '19:00',
            'flexStartFrom' => EngineFixtures::at('08:00'), 'flexStartTo' => EngineFixtures::at('10:00'),
            'coreStart' => EngineFixtures::at('11:00'), 'coreEnd' => EngineFixtures::at('15:00'),
        ];

        return EngineFixtures::day($punchSpecs, $rules, $sched + $base, $o);
    }

    public function test_arrival_inside_flex_window_is_never_late(): void
    {
        $r = $this->flex(['09:55', '17:55']);
        $this->assertSame(0, $r->lateMinutes);
        $this->assertSame(0, $r->earlyExitMinutes);
        $this->assertSame('present', $r->status);
    }

    public function test_expected_end_follows_actual_start_plus_required_minutes(): void
    {
        $r = $this->flex(['08:30', '16:00']);
        $this->assertSame(20, $r->earlyExitMinutes); // expected end 16:30, grace 10 => 30-10
        $this->assertSame('half_day', $r->status);   // net 450 < 480
        $this->assertSame('11:00', substr(EngineFixtures::step($r, 'flexible.window')['outcome']['expected_end'], 11, 5)); // 16:30 Asia/Kolkata in UTC
    }

    public function test_leaving_exactly_at_flexible_end_is_fine(): void
    {
        $r = $this->flex(['08:30', '16:30']);
        $this->assertSame(0, $r->earlyExitMinutes);
        $this->assertSame('present', $r->status);
    }

    public function test_arriving_before_the_flex_window_counts_from_flex_start(): void
    {
        $r = $this->flex(['07:30', '16:00']);
        $this->assertSame(0, $r->earlyExitMinutes); // effective start 08:00 -> expected end 16:00
        $this->assertSame(0, $r->lateMinutes);
        $this->assertSame(510, $r->netMinutes);
    }

    public function test_pre_shift_overtime_before_flex_start_when_enabled(): void
    {
        $r = $this->flex(['07:00', '16:00'], ['overtime' => ['pre_shift' => true]]);
        $this->assertSame(60, $r->overtimeMinutes);
        $this->assertSame(60, $r->overtimeBreakdown['pre_shift']);
    }

    public function test_arrival_after_flex_window_is_late_by_the_excess(): void
    {
        $r = $this->flex(['10:30', '18:30']);
        $this->assertSame(20, $r->lateMinutes); // 30 - grace 10, beyond_grace
        $this->assertSame(0, $r->earlyExitMinutes); // expected end 18:00
        $this->assertSame('present', $r->status);
    }

    public function test_late_beyond_flex_window_from_start_mode(): void
    {
        $r = $this->flex(['10:30', '18:30'], ['late' => ['grace_mode' => 'from_start']]);
        $this->assertSame(30, $r->lateMinutes);
    }

    public function test_core_hours_not_covered_prevents_a_full_day(): void
    {
        $r = $this->flex(['12:00', '20:00']);
        $this->assertSame(480, $r->netMinutes);
        $this->assertSame('half_day', $r->status);
        $this->assertTrue($r->flags['core_hours_not_met']);
        $this->assertNotNull(EngineFixtures::exception($r, 'shift_mismatch'));
    }

    public function test_core_hours_requirement_can_be_switched_off(): void
    {
        $r = $this->flex(['12:00', '20:00'], ['status' => ['present_requires_core_hours' => false]]);
        $this->assertSame('present', $r->status);
    }

    public function test_core_hours_covered_with_a_lunch_break_inside_still_counts(): void
    {
        $r = $this->flex(['08:00', '12:00', '13:00', '17:00']);
        $this->assertFalse($r->flags['core_hours_not_met']);
        $this->assertSame(480, $r->netMinutes);
        $this->assertSame('present', $r->status);
    }

    public function test_flexible_expected_end_includes_scheduled_unpaid_break(): void
    {
        $r = $this->flex(['08:00', '17:00'], [], ['breaks' => [['12:00', '13:00', false]]]);
        $this->assertSame(0, $r->earlyExitMinutes); // expected end 08:00 + 8h + 1h
    }

    // ------------------------------------------------------------------ split shifts

    private function split(array $punchSpecs, array $rules = [], array $o = [], array $extra = [])
    {
        return EngineFixtures::day($punchSpecs, $rules, $o + [
            'shiftKind' => 'split', 'start' => '08:00', 'end' => '20:00',
            'segments' => [['08:00', '12:00'], ['16:00', '20:00']],
        ]);
    }

    public function test_split_shift_gap_between_segments_is_not_a_break_or_work(): void
    {
        $r = $this->split(['08:00', '12:00', '16:00', '20:00']);
        $this->assertSame(480, $r->grossMinutes);
        $this->assertSame(0, $r->breakMinutes);
        $this->assertSame(480, $r->netMinutes);
        $this->assertSame('present', $r->status);
    }

    public function test_split_shift_reports_each_segment(): void
    {
        $r = $this->split(['08:00', '12:00', '16:00', '20:00']);
        $segs = EngineFixtures::step($r, 'split.segments')['outcome']['segments'];
        $this->assertCount(2, $segs);
        $this->assertSame(['full', 'full'], array_column($segs, 'state'));
        $this->assertSame([240, 240], array_column($segs, 'attended_minutes'));
    }

    public function test_missing_second_segment_is_a_half_day_with_a_mismatch_exception(): void
    {
        $r = $this->split(['08:00', '12:00']);
        $this->assertSame(240, $r->netMinutes);
        $this->assertSame('half_day', $r->status);
        $segs = EngineFixtures::step($r, 'split.segments')['outcome']['segments'];
        $this->assertSame(['full', 'missed'], array_column($segs, 'state'));
        $e = EngineFixtures::exception($r, 'shift_mismatch');
        $this->assertSame([2], $e['details']['segments_not_attended']);
    }

    public function test_split_shift_late_is_attributed_to_the_first_start_only(): void
    {
        $r = $this->split(['08:00', '12:00', '16:30', '20:00'], ['hours' => ['full_day_minutes' => 450, 'half_day_minutes' => 225]], [], ['requiredMinutes' => 450]);
        $this->assertSame(0, $r->lateMinutes);
        $this->assertSame(450, $r->netMinutes);
    }

    public function test_split_shift_first_segment_late(): void
    {
        $r = $this->split(['08:30', '12:00', '16:00', '20:00']);
        $this->assertSame(20, $r->lateMinutes);
    }

    public function test_split_shift_early_exit_is_attributed_to_the_last_end(): void
    {
        $r = $this->split(['08:00', '12:00', '16:00', '19:30']);
        $this->assertSame(20, $r->earlyExitMinutes);
    }

    public function test_punched_out_before_the_segment_end_counts_only_the_overlap_gap_as_break(): void
    {
        $r = $this->split(['08:00', '11:30', '16:00', '20:00'], ['hours' => ['full_day_minutes' => 450, 'half_day_minutes' => 225]], [], ['requiredMinutes' => 450]);
        // gap 11:30-16:00 minus the scheduled gap 12:00-16:00 => 30m punched break inside segment 1
        $this->assertSame(30, $r->breakMinutes);
        $this->assertSame(480, $r->grossMinutes);
        $this->assertSame(450, $r->netMinutes);
    }

    public function test_split_shift_overtime_only_after_last_segment(): void
    {
        $r = $this->split(['08:00', '12:00', '16:00', '21:00']);
        $this->assertSame(60, $r->overtimeMinutes);
    }
}
