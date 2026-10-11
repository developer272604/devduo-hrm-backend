<?php

namespace Tests\Unit\Attendance\Engine;

use App\Domain\Attendance\Engine\AttendanceCalculator;
use App\Domain\Attendance\Engine\CalculationInput;
use App\Domain\Attendance\Engine\CalculationResult;
use App\Domain\Attendance\Engine\DaySchedule;
use App\Domain\Attendance\Engine\Punch;
use App\Domain\Attendance\Engine\Segment;
use App\Domain\Attendance\Support\PolicyRules;
use App\Domain\Attendance\Support\TimeZones;
use Carbon\CarbonImmutable;

/**
 * Test factories. Times are written as local wall-clock strings in the schedule timezone:
 *   "09:08", "09:08:30", "+1 06:00" (next calendar day), "-1 23:30", optional direction suffix " IN" | " OUT" | " UNK".
 * Without a suffix punches alternate IN, OUT, IN, ...
 */
final class EngineFixtures
{
    public const DATE = '2026-03-02'; // a Monday, no DST change in Asia/Kolkata

    public const TZ = 'Asia/Kolkata';

    /** @return array{0:int,1:string} */
    private static function parse(string $spec): array
    {
        $offset = 0;
        if (preg_match('/^([+-]\d+)\s+(.*)$/', $spec, $m)) {
            $offset = (int) $m[1];
            $spec = $m[2];
        }

        return [$offset, $spec];
    }

    public static function at(string $spec, string $date = self::DATE, string $tz = self::TZ): CarbonImmutable
    {
        [$offset, $time] = self::parse($spec);

        return TimeZones::localToUtc($date, $time, $tz, $offset);
    }

    /** A fixed-shift working day. $o may override any DaySchedule constructor argument, plus: start/end (local strings), tz, date, breaks (list of [start,end,paid]). */
    public static function schedule(array $o = []): DaySchedule
    {
        $tz = $o['tz'] ?? self::TZ;
        $date = $o['date'] ?? self::DATE;
        $startS = $o['start'] ?? '09:00';
        $endS = $o['end'] ?? '18:00';
        $endOffset = $o['end_offset'] ?? ($endS <= $startS ? 1 : 0);
        $start = TimeZones::localToUtc($date, $startS, $tz);
        $end = TimeZones::localToUtc($date, $endS, $tz, $endOffset);
        $segments = [new Segment($start, $end, 'work')];
        foreach ($o['breaks'] ?? [] as $b) {
            $segments[] = new Segment(self::at($b[0], $date, $tz), self::at($b[1], $date, $tz), 'break', $b[2] ?? false);
        }
        if (isset($o['segments'])) {
            $segments = [];
            foreach ($o['segments'] as $s) {
                $segments[] = new Segment(self::at($s[0], $date, $tz), self::at($s[1], $date, $tz), $s[2] ?? 'work', $s[3] ?? true);
            }
        }
        $args = [
            'employeeId' => 1, 'date' => $date, 'timezone' => $tz, 'kind' => 'working', 'shiftId' => 10, 'shiftCode' => 'GEN', 'shiftKind' => 'fixed', 'segments' => $segments,
            'start' => $start, 'end' => $end, 'requiredMinutes' => 480, 'halfDayMinutes' => 240,
            'windowStart' => $start->subHours(4), 'windowEnd' => $end->addHours(6),
        ];
        foreach (['start', 'end', 'end_offset', 'tz', 'date', 'breaks', 'segments'] as $k) {
            unset($o[$k]);
        }
        foreach ($o as $k => $v) {
            $args[$k] = $v;
        }

        return new DaySchedule(...$args);
    }

    public static function nonWorking(string $kind, array $o = []): DaySchedule
    {
        $tz = $o['tz'] ?? self::TZ;
        $date = $o['date'] ?? self::DATE;
        unset($o['tz'], $o['date']);

        return new DaySchedule(...array_merge(['employeeId' => 1, 'date' => $date, 'timezone' => $tz, 'kind' => $kind], $o));
    }

    /** @param array<int,string|array{0:string,1?:string}> $specs */
    public static function punches(string|array ...$specs): array
    {
        return self::punchesOn(self::DATE, self::TZ, $specs);
    }

    public static function punchesOn(string $date, string $tz, array $specs, array $extra = []): array
    {
        $out = [];
        foreach ($specs as $i => $spec) {
            $spec = (string) (is_array($spec) ? $spec[0] : $spec);
            $dir = $i % 2 === 0 ? 'IN' : 'OUT';
            if (preg_match('/^(.*?)\s+(IN|OUT|UNK)$/', $spec, $m)) {
                $spec = $m[1];
                $dir = $m[2] === 'UNK' ? 'UNKNOWN' : $m[2];
            }
            $out[] = new Punch('event:'.($i + 1), self::at($spec, $date, $tz), $dir, $extra['source'] ?? 'biometric', 'raw', $extra['geofence'] ?? null, $extra['verification'] ?? null);
        }

        return $out;
    }

    /** Builds an input. $rules are OVERRIDES merged over PolicyRules::DEFAULTS. */
    public static function input(DaySchedule $schedule, array $punches, array $rules = [], array $o = []): CalculationInput
    {
        $now = $o['now'] ?? self::at('+2 00:00', $schedule->date, $schedule->timezone); // day is finished by default
        unset($o['now']);
        $args = [
            'schedule' => $schedule, 'rules' => PolicyRules::merge(array_filter($rules, fn ($v) => $v !== [])), 'punches' => $punches, 'now' => $now,
            'policyLabel' => 'Test Attendance Policy v1', 'policyVersionId' => 1,
        ];
        foreach ($o as $k => $v) {
            $args[$k] = $v;
        }

        return new CalculationInput(...$args);
    }

    public static function run(DaySchedule $schedule, array $punches, array $rules = [], array $o = []): CalculationResult
    {
        return AttendanceCalculator::calculate(self::input($schedule, $punches, $rules, $o));
    }

    /** Shortcut: default 09:00-18:00 day with the given punch specs. */
    public static function day(array $punchSpecs, array $rules = [], array $scheduleOpts = [], array $o = []): CalculationResult
    {
        $schedule = self::schedule($scheduleOpts);

        return self::run($schedule, self::punchesOn($schedule->date, $schedule->timezone, $punchSpecs), $rules, $o);
    }

    public static function exceptionTypes(CalculationResult $r): array
    {
        $t = array_column($r->exceptions, 'type');
        sort($t);

        return $t;
    }

    public static function exception(CalculationResult $r, string $type): ?array
    {
        foreach ($r->exceptions as $e) {
            if ($e['type'] === $type) {
                return $e;
            }
        }

        return null;
    }

    public static function step(CalculationResult $r, string $name): ?array
    {
        foreach ($r->steps as $s) {
            if ($s['step'] === $name) {
                return $s;
            }
        }

        return null;
    }
}
