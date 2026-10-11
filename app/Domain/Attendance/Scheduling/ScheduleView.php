<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Support\TimeZones;
use Illuminate\Support\Facades\DB;

/** Employee-facing, small payloads: own schedule (me) and a manager's team view. */
final class ScheduleView
{
    public function __construct(private DatabaseScheduleResolver $resolver) {}

    /** Own schedule. Times appear in the SHIFT timezone and in the viewer's (employee's) display timezone. */
    public function mine(int $employeeId, string $from, string $to): array
    {
        if ($to < $from || \Carbon\CarbonImmutable::parse($from)->diffInDays($to) > 62) {
            Fail::field('to', 'Choose a range of at most 62 days.');
        }
        $decider = ScheduleDecider::load([$employeeId], $from, $to);
        $employee = $decider->org->employee($employeeId);
        $viewerZone = $employee['timezone'] ?? 'UTC';
        $days = [];
        foreach (DatabaseScheduleResolver::dates($from, $to) as $date) {
            $d = $decider->decide($employeeId, $date);
            $s = $this->resolver->materialize($d);
            $assignment = $decider->org->assignmentOn($employeeId, $date);
            $day = ['date' => $date, 'kind' => $d['kind'], 'weekly_off' => $d['kind'] === 'weekly_off' || ($d['kind'] === 'holiday' && (bool) preg_grep('/weekly off/', $d['notes'])),
                'holiday' => $d['holiday'] ? ['name' => $d['holiday']['name'], 'type' => $d['holiday']['type'], 'portion' => $d['holiday']['portion']] : null,
                'changed' => (bool) ($d['roster_entry']['previous_entry_id'] ?? false), 'location' => $decider->org->locationName($assignment['location_id'] ?? null), 'shift' => null];
            if ($s->kind === 'working' && $s->start && $s->end) {
                $shift = $d['shift'];
                $day['shift'] = ['code' => $shift['code'], 'name' => $shift['name'], 'timezone' => $shift['timezone'], 'start' => substr($shift['start_time'], 0, 5), 'end' => substr($shift['end_time'], 0, 5),
                    'crosses_midnight' => (bool) $shift['crosses_midnight'], 'start_local' => TimeZones::utcToLocal($s->start, $viewerZone)->format('H:i'), 'end_local' => TimeZones::utcToLocal($s->end, $viewerZone)->format('H:i'),
                    'viewer_timezone' => $viewerZone, 'kind' => $shift['kind'], 'color' => $shift['color_token']];
            }
            $days[] = $day;
        }
        $today = TenantClockToday::for($viewerZone);
        $todayRow = collect($days)->firstWhere('date', $today);
        $next = collect($days)->first(fn ($x) => $x['date'] > $today && $x['shift']);

        return ['from' => $from, 'to' => $to, 'timezone' => $viewerZone, 'today' => $todayRow, 'next' => $next, 'days' => $days];
    }

    /** Compact team grid: employee => date => shift code / OFF / HOL. */
    public function team(array $employeeIds, string $from, string $to): array
    {
        $decider = ScheduleDecider::load($employeeIds, $from, $to);
        $names = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', $employeeIds ?: [0])->pluck('people.display_name', 'employees.id');
        $rows = [];
        foreach ($employeeIds as $id) {
            $cells = [];
            foreach (DatabaseScheduleResolver::dates($from, $to) as $date) {
                $d = $decider->decide($id, $date);
                $cells[$date] = match ($d['kind']) {
                    'working' => $d['shift']['code'] ?? '?', 'weekly_off' => 'OFF', 'holiday' => 'HOL', default => '-',
                };
            }
            $rows[] = ['employee' => $names[$id] ?? null, 'days' => $cells];
        }

        return $rows;
    }
}

/** Tiny helper: today's date in a given zone. */
final class TenantClockToday
{
    public static function for(string $zone): string
    {
        return \App\Domain\Tenancy\TenantClock::today($zone);
    }
}
