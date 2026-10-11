<?php

namespace App\Domain\Attendance\Operations;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Operational attendance reports (spec 80). One catalogue: each report = base query + columns + date column.
 * Always tenant + DataScope limited (attendance.reports.view), no coordinates anywhere, whitelisted sorting, page size <= 100.
 */
final class ReportCatalogue
{
    public const PERMISSIONS = ['attendance.reports.view', 'attendance.manage'];

    public const FILTERS = ['q', 'employee', 'employee_number', 'department_id', 'location_id', 'legal_entity_id', 'shift_id', 'from', 'to', 'status', 'exception_type', 'manager', 'source', 'policy_id', 'device_id'];

    private const DAY_COLS = ['date' => 'd.attendance_date', 'status' => 'd.status', 'shift' => 's.code', 'first_in' => 'd.first_in', 'last_out' => 'd.last_out', 'required_minutes' => 'd.required_minutes',
        'net_minutes' => 'd.net_minutes', 'late_minutes' => 'd.late_minutes', 'early_exit_minutes' => 'd.early_exit_minutes', 'overtime_minutes' => 'd.overtime_minutes',
        'approved_overtime_minutes' => 'd.approved_overtime_minutes', 'open_exceptions' => 'd.open_exceptions', 'confidence' => 'd.confidence', 'finalization' => 'd.finalization'];

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        $days = fn (?\Closure $extra = null) => function (Builder $q) use ($extra) {
            $q->from('attendance_days as d')->join('employees as e', 'e.id', '=', 'd.employee_id')->leftJoin('shifts as s', 's.id', '=', 'd.shift_id');
            if ($extra) {
                $extra($q);
            }
        };

        return [
            'daily' => ['title' => 'Daily attendance', 'date' => 'd.attendance_date', 'cols' => self::DAY_COLS, 'base' => $days(), 'sorts' => ['date', 'status', 'late_minutes', 'net_minutes'], 'default_sort' => 'date'],
            'monthly' => ['title' => 'Monthly attendance', 'date' => 'd.attendance_date', 'cols' => self::DAY_COLS, 'base' => $days(), 'sorts' => ['date', 'status'], 'default_sort' => 'date'],
            'late_arrival' => ['title' => 'Late arrivals', 'date' => 'd.attendance_date', 'cols' => self::DAY_COLS, 'base' => $days(fn ($q) => $q->where('d.late_minutes', '>', 0)), 'sorts' => ['date', 'late_minutes'], 'default_sort' => 'date'],
            'early_departure' => ['title' => 'Early departures', 'date' => 'd.attendance_date', 'cols' => self::DAY_COLS, 'base' => $days(fn ($q) => $q->where('d.early_exit_minutes', '>', 0)), 'sorts' => ['date', 'early_exit_minutes'], 'default_sort' => 'date'],
            'missing_punch' => ['title' => 'Missing punches', 'date' => 'd.attendance_date', 'cols' => self::DAY_COLS, 'base' => $days(fn ($q) => $q->where(fn ($w) => $w->where('d.missing_punch', true)->orWhere('d.status', 'missing_punch'))), 'sorts' => ['date'], 'default_sort' => 'date'],
            'overtime' => ['title' => 'Overtime', 'date' => 'd.attendance_date', 'cols' => self::DAY_COLS, 'base' => $days(fn ($q) => $q->where(fn ($w) => $w->where('d.overtime_minutes', '>', 0)->orWhere('d.approved_overtime_minutes', '>', 0))), 'sorts' => ['date', 'overtime_minutes', 'approved_overtime_minutes'], 'default_sort' => 'date'],
            'shift' => ['title' => 'Shift report', 'date' => 'd.attendance_date', 'cols' => ['date' => 'd.attendance_date', 'shift' => 's.code', 'shift_name' => 's.name', 'status' => 'd.status', 'scheduled_start' => 'd.scheduled_start', 'scheduled_end' => 'd.scheduled_end'], 'base' => $days(), 'sorts' => ['date', 'shift'], 'default_sort' => 'date'],
            'summary' => ['title' => 'Attendance summary', 'date' => 'd.attendance_date', 'cols' => self::DAY_COLS, 'base' => $days(), 'sorts' => ['date'], 'default_sort' => 'date', 'summarize' => true],
            'exceptions' => ['title' => 'Exceptions', 'date' => 'x.attendance_date', 'cols' => ['date' => 'x.attendance_date', 'type' => 'x.type', 'severity' => 'x.severity', 'status' => 'x.status', 'detected_at' => 'x.detected_at', 'resolution' => 'x.resolution'],
                'base' => fn (Builder $q) => $q->from('attendance_exceptions as x')->join('employees as e', 'e.id', '=', 'x.employee_id'), 'sorts' => ['date', 'severity', 'type', 'status'], 'default_sort' => 'date', 'status' => 'x.status', 'type' => 'x.type'],
            'regularization' => ['title' => 'Regularizations', 'date' => 'r.attendance_date', 'cols' => ['date' => 'r.attendance_date', 'type' => 'r.type', 'status' => 'r.status', 'requested_in' => 'r.requested_in', 'requested_out' => 'r.requested_out', 'reason' => 'r.reason', 'decided_at' => 'r.decided_at'],
                'base' => fn (Builder $q) => $q->from('attendance_regularizations as r')->join('employees as e', 'e.id', '=', 'r.employee_id'), 'sorts' => ['date', 'status', 'type'], 'default_sort' => 'date', 'status' => 'r.status'],
            'on_duty' => ['title' => 'On duty', 'date' => 'o.start_date', 'cols' => ['start_date' => 'o.start_date', 'end_date' => 'o.end_date', 'type' => 'o.type', 'status' => 'o.status', 'reason' => 'o.reason', 'location_text' => 'o.location_text'],
                'base' => fn (Builder $q) => $q->from('on_duty_requests as o')->join('employees as e', 'e.id', '=', 'o.employee_id'), 'sorts' => ['start_date', 'status'], 'default_sort' => 'start_date', 'status' => 'o.status'],
            'roster' => ['title' => 'Roster', 'date' => 'ro.work_date', 'cols' => ['date' => 'ro.work_date', 'entry_type' => 'ro.entry_type', 'shift' => 's.code', 'status' => 'ro.status', 'publication_version' => 'ro.publication_version'],
                'base' => fn (Builder $q) => $q->from('roster_entries as ro')->join('employees as e', 'e.id', '=', 'ro.employee_id')->leftJoin('shifts as s', 's.id', '=', 'ro.shift_id')->where('ro.status', '!=', 'superseded'), 'sorts' => ['date', 'status'], 'default_sort' => 'date', 'status' => 'ro.status'],
            'device_punch' => ['title' => 'Device punches', 'date' => 'ev.local_date', 'cols' => ['date' => 'ev.local_date', 'punched_at' => 'ev.punched_at', 'direction' => 'ev.direction', 'source' => 'ev.source', 'device' => 'dv.name', 'timezone' => 'ev.timezone', 'geofence_status' => 'ev.geofence_status'],
                'base' => fn (Builder $q) => $q->from('attendance_events as ev')->join('employees as e', 'e.id', '=', 'ev.employee_id')->leftJoin('attendance_devices as dv', 'dv.id', '=', 'ev.device_id')->whereNull('ev.voided_at'), 'sorts' => ['date', 'punched_at', 'direction'], 'default_sort' => 'punched_at', 'source' => 'ev.source', 'device' => 'ev.device_id'],
        ];
    }

    public function exists(string $key): bool
    {
        return isset($this->definitions()[$key]);
    }

    public function catalogue(): array
    {
        return collect($this->definitions())->map(fn ($d, $k) => ['key' => $k, 'title' => $d['title'], 'columns' => array_keys($d['cols']), 'sorts' => $d['sorts']])->values()->all();
    }

    /** Builds the filtered, scoped query (no ordering/paging). */
    public function query(User $user, string $key, array $f): Builder
    {
        $def = $this->definitions()[$key] ?? abort(404, 'That report does not exist.');
        $q = DB::query();
        $def['base']($q);
        $q->join('people as p', 'p.id', '=', 'e.person_id')->where('e.tenant_id', Ops::tenantId());
        $scoped = Ops::employees($user, self::PERMISSIONS)->select('e.id');
        $q->whereIn('e.id', $scoped);
        $today = Ops::today();
        $q->leftJoin('employee_assignments as a', function ($j) use ($today) {
            $j->on('a.employee_id', '=', 'e.id')->where('a.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $today));
        });
        $date = $def['date'];
        if (! empty($f['from'])) {
            $q->where($date, '>=', $f['from']);
        }
        if (! empty($f['to'])) {
            $q->where($date, '<=', $f['to']);
        }
        if (! empty($f['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $f['q']).'%';
            $q->where(fn ($w) => $w->where('p.display_name', 'like', $term)->orWhere('e.employee_number', 'like', $term));
        }
        if (! empty($f['employee'])) {
            $q->where('e.uuid', $f['employee']);
        }
        if (! empty($f['employee_number'])) {
            $q->where('e.employee_number', $f['employee_number']);
        }
        foreach (['department_id', 'location_id', 'legal_entity_id'] as $dim) {
            if (! empty($f[$dim])) {
                $q->where('a.'.$dim, (int) $f[$dim]);
            }
        }
        if (! empty($f['manager'])) {
            $q->whereExists(function ($s) use ($f, $today) {
                $s->from('employee_reporting_relationships as rr')->join('employees as m', 'm.id', '=', 'rr.manager_id')->whereColumn('rr.employee_id', 'e.id')->where('rr.type', 'primary')
                    ->where('rr.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('rr.effective_to')->orWhere('rr.effective_to', '>=', $today))->where('m.uuid', $f['manager']);
            });
        }
        $shiftCol = in_array($key, ['daily', 'monthly', 'late_arrival', 'early_departure', 'missing_punch', 'overtime', 'shift', 'summary'], true) ? 'd.shift_id' : ($key === 'roster' ? 'ro.shift_id' : null);
        if (! empty($f['shift_id']) && $shiftCol) {
            $q->where($shiftCol, (int) $f['shift_id']);
        }
        if (! empty($f['status'])) {
            $q->where($def['status'] ?? 'd.status', $f['status']);
        }
        if (! empty($f['exception_type'])) {
            if (isset($def['type']) && $key === 'exceptions') {
                $q->where('x.type', $f['exception_type']);
            } elseif ($shiftCol && str_starts_with($shiftCol, 'd.')) {
                $q->whereExists(fn ($s) => $s->from('attendance_exceptions as xe')->whereColumn('xe.day_id', 'd.id')->where('xe.type', $f['exception_type']));
            }
        }
        if (! empty($f['source']) && isset($def['source'])) {
            $q->where($def['source'], $f['source']);
        }
        if (! empty($f['device_id']) && isset($def['device'])) {
            $q->where($def['device'], (int) $f['device_id']);
        }
        if (! empty($f['policy_id']) && $shiftCol && str_starts_with($shiftCol, 'd.')) {
            $q->whereExists(fn ($s) => $s->from('attendance_policy_versions as pv')->whereColumn('pv.id', 'd.policy_version_id')->where('pv.policy_id', (int) $f['policy_id']));
        }

        return $q;
    }

    private function select(string $key, Builder $q): Builder
    {
        $def = $this->definitions()[$key];
        $cols = ['e.uuid as employee_uuid', 'e.employee_number', 'p.display_name as employee_name'];
        foreach ($def['cols'] as $alias => $expr) {
            $cols[] = $expr.' as '.$alias;
        }

        return $q->select($cols);
    }

    /** @return array{data:array,meta:array} */
    public function run(User $user, string $key, array $f, ?string $sort, string $dir, int $page, int $perPage): array
    {
        $def = $this->definitions()[$key] ?? abort(404, 'That report does not exist.');
        $q = $this->query($user, $key, $f);
        $total = (clone $q)->count();
        $sort = in_array($sort, $def['sorts'], true) ? $sort : $def['default_sort'];
        $this->select($key, $q)->orderBy($def['cols'][$sort], $dir === 'asc' ? 'asc' : 'desc')->orderBy('e.id');
        $rows = $q->forPage($page, $perPage)->get()->map(fn ($r) => (array) $r)->all();
        $out = ['data' => $rows, 'meta' => ['report' => $key, 'title' => $def['title'], 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage)), 'sort' => $sort, 'dir' => $dir]];
        if (! empty($def['summarize'])) {
            $out['summary'] = $this->summary($user, $f);
        }

        return $out;
    }

    private function summary(User $user, array $f): array
    {
        $q = $this->query($user, 'summary', $f);

        return ['by_status' => (clone $q)->selectRaw('d.status as k, count(*) as c')->groupBy('d.status')->pluck('c', 'k')->all(),
            'late_arrivals' => (clone $q)->where('d.late_minutes', '>', 0)->count(), 'early_departures' => (clone $q)->where('d.early_exit_minutes', '>', 0)->count(),
            'worked_minutes' => (int) (clone $q)->sum('d.net_minutes'), 'approved_overtime_minutes' => (int) (clone $q)->sum('d.approved_overtime_minutes'),
            'by_confidence' => (clone $q)->selectRaw('d.confidence as k, count(*) as c')->groupBy('d.confidence')->pluck('c', 'k')->all()];
    }

    /** Streams all rows (id order chunks) for export. @return \Generator<int,array> */
    public function stream(User $user, string $key, array $f, int $limit = 100000): \Generator
    {
        $def = $this->definitions()[$key] ?? abort(404);
        $page = 1;
        $n = 0;
        while ($n < $limit) {
            $rows = $this->select($key, $this->query($user, $key, $f))->orderBy($def['cols'][$def['default_sort']])->orderBy('e.id')->forPage($page++, 500)->get();
            if ($rows->isEmpty()) {
                return;
            }
            foreach ($rows as $r) {
                yield (array) $r;
                $n++;
            }
        }
    }
}
