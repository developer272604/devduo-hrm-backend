<?php

namespace Tests\Unit;

use App\Domain\Attendance\Support\TimeZones;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TimeZonesTest extends TestCase
{
    public function test_local_to_utc_uses_the_zone_not_the_server(): void
    {
        $this->assertSame('2026-10-07T04:30:00+00:00', TimeZones::localToUtc('2026-10-07', '10:00', 'Asia/Kolkata')->toIso8601String());
        $this->assertSame('2026-01-15T14:00:00+00:00', TimeZones::localToUtc('2026-01-15', '09:00', 'America/New_York')->toIso8601String());
    }

    public function test_cross_midnight_offset_adds_a_calendar_day(): void
    {
        $start = TimeZones::localToUtc('2026-10-07', '22:00', 'Asia/Kolkata');
        $end = TimeZones::localToUtc('2026-10-07', '06:00', 'Asia/Kolkata', 1);
        $this->assertSame(480, (int) $start->diffInMinutes($end));
    }

    public function test_spring_forward_gap_moves_the_nonexistent_time_forward(): void
    {
        // US 2026-03-08 02:00 -> 03:00; 02:30 does not exist.
        $this->assertSame('2026-03-08T07:30:00+00:00', TimeZones::localToUtc('2026-03-08', '02:30', 'America/New_York')->toIso8601String());
    }

    public function test_fall_back_overlap_resolves_to_the_first_occurrence(): void
    {
        // Europe/London 2026-10-25: 01:30 happens twice; first is BST (UTC+1) => 00:30Z.
        $this->assertSame('2026-10-25T00:30:00+00:00', TimeZones::localToUtc('2026-10-25', '01:30', 'Europe/London')->toIso8601String());
    }

    public function test_night_shift_across_a_dst_change_is_nine_real_hours(): void
    {
        // 22:00 -> 06:00 across London fall-back (2026-10-24 -> 25): 9 real hours.
        $start = TimeZones::localToUtc('2026-10-24', '22:00', 'Europe/London');
        $end = TimeZones::localToUtc('2026-10-24', '06:00', 'Europe/London', 1);
        $this->assertSame(540, (int) $start->diffInMinutes($end));
        $this->assertTrue(TimeZones::dstChangesBetween('Europe/London', '2026-10-24', '2026-10-25'));
    }

    public function test_local_date_of_an_instant_depends_on_the_zone(): void
    {
        $instant = CarbonImmutable::parse('2026-10-07 20:00:00', 'UTC');
        $this->assertSame('2026-10-07', TimeZones::localDate($instant, 'America/New_York'));
        $this->assertSame('2026-10-08', TimeZones::localDate($instant, 'Asia/Kolkata'));
        $this->assertTrue(TimeZones::isValid('Asia/Kolkata'));
        $this->assertFalse(TimeZones::isValid('Mars/Base'));
    }
}
