<?php

namespace App\Domain\Attendance\Operations;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Support\AttendanceStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Manager/HR view of a day or a range, limited to the viewer's data scope. Never exposes coordinates. */
final class TeamAttendance
{
    public const PERMISSION = 'attendance.team.view';

    /** @return array<string,mixed> */
    public function today(User $user, ?string $date = null, int $limit = 25): array
    {
        $date ??= Ops::today();
        $limit = max(1, min(100, $limit));
        $tenant = Ops::tenantId();
        $own = Ops::ownEmployee($user)?->id;
        $people = Ops::employees($user, self::PERMISSION, true)->when($own, fn ($q) => $q->where('e.id', '!=', $own))
            ->select('e.id', 'e.uuid', 'e.employee_number', 'p.display_name')->orderBy('p.display_name')->get();
        $ids = $people->pluck('id')->map(fn ($v) => (int) $v)->all();

        $days = DB::table('attendance_days')->where('tenant_id', $tenant)->where('attendance_date', $date)->whereIn('employee_id', $ids ?: [0])->get()->keyBy('employee_id');
        $last = $this->lastEvents($ids, $date);
        $leave = $ids ? app(LeaveDataProvider::class)->coverageForRange($ids, $date, $date) : [];
        $now = CarbonImmutable::now('UTC');

        $groups = array_fill_keys(['in', 'checked_out', 'absent', 'not_in_yet', 'late', 'on_leave', 'remote_or_on_duty', 'missing_punches', 'not_scheduled'], []);
        foreach ($people as $p) {
            $id = (int) $p->id;
            $day = $days[$id] ?? null;
            $event = $last[$id] ?? null;
            $status = $day->status ?? 'pending';
            $row = ['employee_uuid' => $p->uuid, 'employee_number' => $p->employee_number, 'name' => $p->display_name, 'status' => $status, 'status_label' => Ops::statusLabel($status),
                'first_in' => Ops::iso($day->first_in ?? null), 'last_out' => Ops::iso($day->last_out ?? null), 'late_minutes' => (int) ($day->late_minutes ?? 0)];
            $coverage = $leave[$id.'|'.$date] ?? null;
            if ($coverage) {
                $groups['on_leave'][] = $row + ['portion' => $coverage['portion'] ?? 'full_day'];
            }
            $in = $event ? $event->direction === 'IN' : ($day && $day->first_in && ! $day->last_out);
            if ($in) {
                $groups['in'][] = $row;
            } elseif ($event || ($day && $day->last_out)) {
                $groups['checked_out'][] = $row;
            }
            if ($day && $day->status === AttendanceStatus::ABSENT && ! $coverage) {
                $groups['absent'][] = $row;
            }
            if ($day && ! $in && ! $event && ! $day->first_in && $day->scheduled_start && CarbonImmutable::parse($day->scheduled_start, 'UTC')->lessThanOrEqualTo($now)
                && ! in_array($status, [AttendanceStatus::ABSENT, AttendanceStatus::WEEKLY_OFF, AttendanceStatus::HOLIDAY, AttendanceStatus::NOT_SCHEDULED, AttendanceStatus::LEAVE], true) && ! $coverage) {
                $groups['not_in_yet'][] = $row;
            }
            if ($day && (int) $day->late_minutes > 0) {
                $groups['late'][] = $row;
            }
            if ($day && (in_array($day->status, [AttendanceStatus::ON_DUTY, AttendanceStatus::REMOTE_WORK], true) || in_array($day->work_mode, ['remote', 'on_duty'], true))) {
                $groups['remote_or_on_duty'][] = $row;
            }
            if ($day && ($day->missing_punch || $day->status === AttendanceStatus::MISSING_PUNCH)) {
                $groups['missing_punches'][] = $row;
            }
            if ($day && in_array($day->status, [AttendanceStatus::WEEKLY_OFF, AttendanceStatus::HOLIDAY, AttendanceStatus::NOT_SCHEDULED], true)) {
                $groups['not_scheduled'][] = $row;
            }
        }
        $pending = DB::table('attendance_regularizations as r')->join('employees as e', 'e.id', '=', 'r.employee_id')->join('people as p', 'p.id', '=', 'e.person_id')
            ->where('r.tenant_id', $tenant)->where('r.status', 'pending')->whereIn('r.employee_id', $ids ?: [0])->orderBy('r.attendance_date')
            ->select('r.uuid', 'r.attendance_date', 'r.type', 'e.uuid as employee_uuid', 'e.employee_number', 'p.display_name')->get();

        $out = ['date' => $date, 'team_size' => count($ids), 'groups' => []];
        $titles = ['in' => "Who's in", 'checked_out' => 'Checked out', 'absent' => 'Absent', 'not_in_yet' => 'Not in yet', 'late' => 'Late arrivals', 'on_leave' => 'On leave', 'remote_or_on_duty' => 'Remote or on duty', 'missing_punches' => 'Missing punches', 'not_scheduled' => 'Not scheduled (weekly off / holiday)'];
        foreach ($groups as $key => $rows) {
            $out['groups'][$key] = ['title' => $titles[$key], 'count' => count($rows), 'employees' => array_slice($rows, 0, $limit)] + ($key === 'on_leave' ? ['note' => 'Provided by the leave module when it is installed.'] : []);
        }
        $out['groups']['pending_regularizations'] = ['title' => 'Pending regularizations', 'count' => $pending->count(),
            'requests' => $pending->take($limit)->map(fn ($r) => ['uuid' => $r->uuid, 'date' => $r->attendance_date, 'type' => $r->type, 'employee_uuid' => $r->employee_uuid, 'employee_number' => $r->employee_number, 'name' => $r->display_name])->all()];
        $out['counts'] = ['in' => count($groups['in']), 'absent' => count($groups['absent']), 'late' => count($groups['late']), 'on_leave' => count($groups['on_leave']), 'remote_or_on_duty' => count($groups['remote_or_on_duty']), 'missing_punches' => count($groups['missing_punches'])];

        return $out;
    }

    /** @return array<string,mixed> */
    public function summary(User $user, string $from, string $to, int $page, int $perPage): array
    {
        $tenant = Ops::tenantId();
        $own = Ops::ownEmployee($user)?->id;
        $base = Ops::employees($user, self::PERMISSION, true)->when($own, fn ($q) => $q->where('e.id', '!=', $own));
        $total = (clone $base)->count();
        $people = (clone $base)->select('e.id', 'e.uuid', 'e.employee_number', 'p.display_name')->orderBy('p.display_name')->orderBy('e.id')->forPage($page, $perPage)->get();
        $allIds = (clone $base)->pluck('e.id')->map(fn ($v) => (int) $v)->all();

        $days = DB::table('attendance_days')->where('tenant_id', $tenant)->whereBetween('attendance_date', [$from, $to])->whereIn('employee_id', $allIds ?: [0])
            ->get(['employee_id', 'status', 'late_minutes', 'early_exit_minutes', 'missing_punch', 'net_minutes', 'overtime_minutes', 'approved_overtime_minutes', 'open_exceptions']);
        $byEmployee = $days->groupBy('employee_id');
        $rows = [];
        foreach ($people as $p) {
            $mine = $byEmployee[$p->id] ?? collect();
            $rows[] = ['employee_uuid' => $p->uuid, 'employee_number' => $p->employee_number, 'name' => $p->display_name] + $this->aggregate($mine);
        }

        return [
            'from' => $from, 'to' => $to, 'team_size' => $total, 'totals' => $this->aggregate($days),
            'data' => $rows, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))],
            'pending' => [
                'regularizations' => DB::table('attendance_regularizations')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $allIds ?: [0])->count(),
                'overtime' => DB::table('overtime_requests')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $allIds ?: [0])->count(),
                'on_duty' => DB::table('on_duty_requests')->where('tenant_id', $tenant)->where('status', 'pending')->whereIn('employee_id', $allIds ?: [0])->count(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function aggregate($days): array
    {
        $by = [];
        foreach ($days->countBy('status') as $status => $n) {
            $by[$status] = $n;
        }

        return ['days_recorded' => $days->count(), 'by_status' => $by, 'late_arrivals' => $days->where('late_minutes', '>', 0)->count(), 'early_departures' => $days->where('early_exit_minutes', '>', 0)->count(),
            'missing_punches' => $days->filter(fn ($d) => (bool) $d->missing_punch)->count(), 'worked_minutes' => (int) $days->sum('net_minutes'), 'approved_overtime_minutes' => (int) $days->sum('approved_overtime_minutes'),
            'open_exceptions' => (int) $days->sum('open_exceptions')];
    }

    /** Latest non-voided event of each employee on the local date. @return array<int,object> */
    public function lastEvents(array $ids, string $date): array
    {
        $out = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $rows = DB::table('attendance_events')->where('tenant_id', Ops::tenantId())->where('local_date', $date)->whereNull('voided_at')->whereIn('employee_id', $chunk)
                ->orderBy('punched_at')->orderBy('id')->get(['employee_id', 'direction', 'punched_at']);
            foreach ($rows as $r) {
                $out[(int) $r->employee_id] = $r;
            }
        }

        return $out;
    }
}
