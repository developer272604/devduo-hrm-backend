<?php

namespace App\Domain\Attendance\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The only place attendance code converts between local wall-clock time and UTC instants.
 * Never compare browser-local times; always go through these helpers with an explicit IANA zone.
 *
 * DST rules (PHP's tz database behaviour, relied on deliberately):
 *  - a local time that does NOT exist (spring-forward gap) is moved forward by the gap (02:30 -> 03:30);
 *  - a local time that occurs TWICE (fall-back overlap) resolves to its FIRST occurrence (the earlier instant).
 * Day offsets add CALENDAR days before applying the time, so a shift that starts at 22:00 and ends at 06:00 the next
 * calendar day is 8 wall-clock hours except across a DST change, where it is 7 or 9 real hours — which is correct.
 */
final class TimeZones
{
    public static function isValid(?string $zone): bool
    {
        return $zone !== null && $zone !== '' && in_array($zone, \DateTimeZone::listIdentifiers(), true);
    }

    /** Local date (Y-m-d) + local time (H:i[:s]) in $zone, plus whole calendar days, as a UTC instant. */
    public static function localToUtc(string $date, string $time, string $zone, int $dayOffset = 0): CarbonImmutable
    {
        $time = strlen($time) === 5 ? $time.':00' : $time;
        $base = new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
        $day = $dayOffset ? $base->modify(($dayOffset > 0 ? '+' : '').$dayOffset.' day')->format('Y-m-d') : $date;
        $tz = new DateTimeZone($zone);
        $local = new DateTimeImmutable($day.' '.$time, $tz);

        // PHP resolves a fall-back overlap to the LATER instant. Business rule here: the FIRST occurrence, so detect
        // a fold from the zone's real transitions instead of depending on engine defaults.
        $wall = (new DateTimeImmutable($day.' '.$time, new DateTimeZone('UTC')))->getTimestamp(); // wall clock read as if it were UTC
        $around = $local->getTimestamp();
        $transitions = $tz->getTransitions($around - 172800, $around + 172800);
        for ($i = 1, $n = count($transitions); $i < $n; $i++) {
            $before = $transitions[$i - 1]['offset'];
            $after = $transitions[$i]['offset'];
            $at = $transitions[$i]['ts'];
            if ($after < $before && $wall >= $at + $after && $wall < $at + $before) {
                return CarbonImmutable::createFromTimestampUTC($wall - $before);
            }
        }

        return CarbonImmutable::instance($local)->utc();
    }

    public static function utcToLocal(CarbonImmutable|\DateTimeInterface $instant, string $zone): CarbonImmutable
    {
        return CarbonImmutable::instance($instant instanceof CarbonImmutable ? $instant : CarbonImmutable::instance($instant))->setTimezone($zone);
    }

    /** The calendar date of an instant as observed in $zone. */
    public static function localDate(CarbonImmutable|\DateTimeInterface $instant, string $zone): string
    {
        return self::utcToLocal($instant, $zone)->toDateString();
    }

    public static function offsetMinutes(CarbonImmutable $instant, string $zone): int
    {
        return (int) (new DateTimeZone($zone))->getOffset($instant->toDateTimeImmutable()) / 60;
    }

    /** True when a zone has a different UTC offset on the two dates (used to flag DST-affected shifts in explanations). */
    public static function dstChangesBetween(string $zone, string $fromDate, string $toDate): bool
    {
        return self::offsetMinutes(self::localToUtc($fromDate, '12:00', $zone), $zone) !== self::offsetMinutes(self::localToUtc($toDate, '12:00', $zone), $zone);
    }
}
