<?php

namespace Tests\Unit\Attendance\Engine;

use App\Domain\Attendance\Engine\Punch;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TimeAndTimezoneTest extends TestCase
{
    public function test_cross_midnight_night_shift_with_punches_next_morning_is_one_attendance_day(): void
    {
        $sch = EngineFixtures::schedule(['start' => '22:00', 'end' => '06:00', 'requiredMinutes' => 480, 'halfDayMinutes' => 240]);
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn(EngineFixtures::DATE, EngineFixtures::TZ, ['21:55', '+1 06:03']));
        $this->assertSame('present', $r->status);
        $this->assertCount(1, $r->pairs);
        $this->assertSame(488, $r->grossMinutes);
        $this->assertSame(0, $r->lateMinutes);
        $this->assertSame(0, $r->earlyExitMinutes);
    }

    public function test_night_shift_late_arrival_and_early_exit_use_utc_instants_across_midnight(): void
    {
        $sch = EngineFixtures::schedule(['start' => '22:00', 'end' => '06:00']);
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn(EngineFixtures::DATE, EngineFixtures::TZ, ['22:40', '+1 05:20']));
        $this->assertSame(30, $r->lateMinutes);
        $this->assertSame(30, $r->earlyExitMinutes);
        $this->assertSame(400, $r->netMinutes);
    }

    public function test_night_shift_missing_out_when_the_out_punch_is_after_the_window(): void
    {
        $sch = EngineFixtures::schedule(['start' => '22:00', 'end' => '06:00']);
        // window end = 12:00 next day; OUT at 13:00 is outside
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn(EngineFixtures::DATE, EngineFixtures::TZ, ['22:00', '+1 13:00']));
        $this->assertSame('missing_punch', $r->status);
        $this->assertNotNull(EngineFixtures::exception($r, 'unexpected_punch'));
    }

    public function test_punch_exactly_at_midnight_belongs_to_the_logical_day_by_instants(): void
    {
        $sch = EngineFixtures::schedule(['start' => '16:00', 'end' => '00:00', 'requiredMinutes' => 480, 'halfDayMinutes' => 240]);
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn(EngineFixtures::DATE, EngineFixtures::TZ, ['16:00', '+1 00:00']));
        $this->assertSame('present', $r->status);
        $this->assertSame(480, $r->grossMinutes);
        $this->assertSame(0, $r->earlyExitMinutes);
    }

    public function test_midnight_punch_on_a_weekly_off_counts_as_work_on_that_day(): void
    {
        $sch = EngineFixtures::nonWorking('weekly_off');
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn(EngineFixtures::DATE, EngineFixtures::TZ, ['00:00', '04:00']));
        $this->assertSame('present', $r->status);
        $this->assertSame(240, $r->netMinutes);
    }

    public function test_spring_forward_night_is_seven_real_hours(): void
    {
        $tz = 'America/New_York';
        $sch = EngineFixtures::schedule(['date' => '2026-03-07', 'tz' => $tz, 'start' => '22:00', 'end' => '06:00', 'requiredMinutes' => 420, 'halfDayMinutes' => 210]);
        $this->assertSame(420, (int) $sch->start->diffInMinutes($sch->end));
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn('2026-03-07', $tz, ['22:00', '+1 06:00']));
        $this->assertSame(420, $r->grossMinutes);
        $this->assertSame('present', $r->status);
    }

    public function test_spring_forward_night_against_an_eight_hour_requirement_is_short(): void
    {
        $tz = 'America/New_York';
        $sch = EngineFixtures::schedule(['date' => '2026-03-07', 'tz' => $tz, 'start' => '22:00', 'end' => '06:00', 'requiredMinutes' => 480, 'halfDayMinutes' => 240]);
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn('2026-03-07', $tz, ['22:00', '+1 06:00']));
        $this->assertSame(420, $r->netMinutes);
        $this->assertSame('half_day', $r->status);
    }

    public function test_fall_back_night_is_nine_real_hours(): void
    {
        $tz = 'America/New_York';
        $sch = EngineFixtures::schedule(['date' => '2026-10-31', 'tz' => $tz, 'start' => '22:00', 'end' => '06:00', 'requiredMinutes' => 540, 'halfDayMinutes' => 270]);
        $this->assertSame(540, (int) $sch->start->diffInMinutes($sch->end));
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn('2026-10-31', $tz, ['22:00', '+1 06:00']));
        $this->assertSame(540, $r->grossMinutes);
        $this->assertSame('present', $r->status);
        $this->assertSame(0, $r->overtimeMinutes);
    }

    public function test_dst_night_overtime_counts_real_minutes_after_shift_end(): void
    {
        $tz = 'America/New_York';
        $sch = EngineFixtures::schedule(['date' => '2026-03-07', 'tz' => $tz, 'start' => '22:00', 'end' => '06:00', 'requiredMinutes' => 420, 'halfDayMinutes' => 210]);
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn('2026-03-07', $tz, ['22:00', '+1 07:00']));
        $this->assertSame(60, $r->overtimeMinutes);
    }

    public function test_employee_in_another_timezone_than_hq_uses_schedule_instants(): void
    {
        $tz = 'America/New_York';
        $sch = EngineFixtures::schedule(['date' => '2026-03-02', 'tz' => $tz, 'start' => '09:00', 'end' => '17:00', 'requiredMinutes' => 450, 'halfDayMinutes' => 225]);
        // 09:12 New York = 14:12Z; HQ is Kolkata but must not matter
        $punches = [new Punch('event:1', CarbonImmutable::parse('2026-03-02T14:12:00Z'), 'IN'), new Punch('event:2', CarbonImmutable::parse('2026-03-02T22:00:00Z'), 'OUT')];
        $r = EngineFixtures::run($sch, $punches);
        $this->assertSame(2, $r->lateMinutes);
        $this->assertSame(0, $r->earlyExitMinutes);
        $this->assertSame('present', $r->status);
    }

    public function test_explanation_times_are_shown_in_the_shift_timezone_with_abbreviation(): void
    {
        $tz = 'America/New_York';
        $sch = EngineFixtures::schedule(['date' => '2026-03-02', 'tz' => $tz, 'start' => '09:00', 'end' => '17:00']);
        $r = EngineFixtures::run($sch, EngineFixtures::punchesOn('2026-03-02', $tz, ['09:00', '17:00']));
        $shift = $r->explanation['lines'][0];
        $this->assertSame('Scheduled shift', $shift['label']);
        $this->assertStringContainsString('09:00–17:00 EST (America/New_York)', $shift['value']);
    }

    public function test_first_in_and_last_out_are_utc_instants(): void
    {
        $r = EngineFixtures::day(['09:00', '18:00']);
        $this->assertSame('2026-03-02T03:30:00+00:00', $r->firstIn->toIso8601String());
        $this->assertSame('2026-03-02T12:30:00+00:00', $r->lastOut->toIso8601String());
    }
}
