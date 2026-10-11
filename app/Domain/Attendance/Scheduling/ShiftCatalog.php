<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Engine\Segment;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loads shift definitions (with segments) as plain arrays and turns them into UTC instants for one logical date.
 * Every wall-clock -> UTC conversion goes through TimeZones (DST-safe); the SHIFT's timezone defines wall time.
 */
final class ShiftCatalog
{
    /** @param array<int,int> $ids @return array<int,array<string,mixed>> keyed by shift id */
    public static function load(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (! $ids) {
            return [];
        }
        $tenant = app(TenantContext::class)->id;
        $shifts = [];
        foreach (DB::table('shifts')->where('tenant_id', $tenant)->whereIn('id', $ids)->get() as $row) {
            $row = (array) $row;
            $row['rules'] = json_decode($row['rules'] ?? 'null', true) ?: [];
            $row['segments'] = [];
            $shifts[$row['id']] = $row;
        }
        foreach (DB::table('shift_segments')->where('tenant_id', $tenant)->whereIn('shift_id', array_keys($shifts))->orderBy('sequence')->get() as $segment) {
            $shifts[$segment->shift_id]['segments'][] = (array) $segment;
        }

        return $shifts;
    }

    /** Stored segment rows, or a synthesized single work segment when the shift carries none. */
    public static function segmentRows(array $shift): array
    {
        if ($shift['segments']) {
            return $shift['segments'];
        }

        return [['sequence' => 1, 'kind' => 'work', 'paid' => true, 'start_time' => $shift['start_time'], 'end_time' => $shift['end_time'],
            'start_day_offset' => 0, 'end_day_offset' => $shift['crosses_midnight'] ? 1 : 0]];
    }

    /** @return array<int,Segment> ordered by start */
    public static function segments(array $shift, string $date): array
    {
        $zone = $shift['timezone'];
        $out = [];
        foreach (self::segmentRows($shift) as $row) {
            $start = TimeZones::localToUtc($date, $row['start_time'], $zone, (int) $row['start_day_offset']);
            $end = TimeZones::localToUtc($date, $row['end_time'], $zone, (int) $row['end_day_offset']);
            $out[] = new Segment($start, $end, $row['kind'], (bool) $row['paid']);
        }
        usort($out, fn (Segment $a, Segment $b) => $a->start <=> $b->start);

        return $out;
    }

    /** Net paid-work minutes of a set of segments (work minutes minus the unpaid break minutes that lie inside work). */
    public static function netMinutes(array $segments): int
    {
        $work = 0;
        $unpaid = 0;
        foreach ($segments as $s) {
            if ($s->kind === 'work') {
                $work += $s->minutes();
            } elseif (! $s->paid) {
                $unpaid += $s->minutes();
            }
        }

        return max(0, $work - $unpaid);
    }

    /** Local wall time -> UTC on a date for window fields; a time earlier than $after rolls to the next calendar day. */
    public static function instant(string $date, ?string $time, string $zone, ?string $notBefore = null): ?CarbonImmutable
    {
        if ($time === null) {
            return null;
        }
        $offset = ($notBefore !== null && substr($time, 0, 5) < substr($notBefore, 0, 5)) ? 1 : 0;

        return TimeZones::localToUtc($date, $time, $zone, $offset);
    }
}
