<?php

namespace App\Domain\Attendance\Operations\Quality;

use App\Domain\Access\Classification;
use App\Domain\Attendance\Operations\Coverage;
use App\Domain\Attendance\Operations\Ops;
use App\Domain\Attendance\Operations\PayrollCutoff;
use App\Domain\Quality\Finding;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The attendance data-quality rules (spec 68). All are READ-ONLY: they describe a problem and where to fix it,
 * never change an employee, assignment or punch. Wording is neutral (setup gaps and facts, not conclusions about people).
 *
 * Employee-level findings use manager_sensitive (the manager of the person may see them); configuration and device
 * findings are hr_confidential. Findings without an employee are visible to tenant-wide viewers only.
 */
final class AttendanceQualityRules
{
    /** @return array<int,AttendanceQualityRule> */
    public static function all(): array
    {
        $r = new self;

        return [
            new AttendanceQualityRule('attendance_no_policy', 'Employees without an attendance policy', fn (int $t) => $r->noPolicy($t)),
            new AttendanceQualityRule('attendance_no_shift', 'Employees without a shift', fn (int $t) => $r->noShift($t)),
            new AttendanceQualityRule('attendance_invalid_shift_assignment', 'Invalid shift assignments', fn (int $t) => $r->invalidAssignments($t)),
            new AttendanceQualityRule('attendance_overlapping_shift_assignments', 'Overlapping shift assignments', fn (int $t) => $r->overlappingAssignments($t)),
            new AttendanceQualityRule('attendance_device_mapping_missing', 'Device employee mapping missing', fn (int $t) => $r->deviceMappings($t)),
            new AttendanceQualityRule('attendance_suspicious_timezone', 'Punch time zone differs from the expected zone', fn (int $t) => $r->timezones($t)),
            new AttendanceQualityRule('attendance_excessive_duplicates', 'Excessive duplicate device events', fn (int $t) => $r->duplicates($t)),
            new AttendanceQualityRule('attendance_unresolved_missing_punch', 'Missing punches unresolved for more than 24 hours', fn (int $t) => $r->missingPunches($t)),
            new AttendanceQualityRule('attendance_not_processed', 'Attendance not processed', fn (int $t) => $r->notProcessed($t)),
            new AttendanceQualityRule('attendance_unlocked_near_cutoff', 'Attendance not locked near the payroll cutoff', fn (int $t) => $r->unlockedNearCutoff($t)),
            new AttendanceQualityRule('attendance_holiday_calendar_missing', 'Holiday calendar missing', fn (int $t) => $r->holidayCalendars($t)),
        ];
    }

    /** Streams working employees in id order as arrays keyed by id => display name, 400 at a time. @return \Generator<int,array<int,string>> */
    private function chunks(int $tenantId): \Generator
    {
        $last = 0;
        while (true) {
            $rows = DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at')->whereNull('p.deleted_at')
                ->whereIn('e.status', Ops::WORKING)->where('e.id', '>', $last)->orderBy('e.id')->limit(400)->get(['e.id', 'e.joined_on', 'p.display_name']);
            if ($rows->isEmpty()) {
                return;
            }
            yield $rows->mapWithKeys(fn ($r) => [(int) $r->id => $r])->all();
            $last = (int) $rows->last()->id;
        }
    }

    public function noPolicy(int $tenantId): iterable
    {
        $today = Ops::today();
        $coverage = new Coverage;
        foreach ($this->chunks($tenantId) as $chunk) {
            $have = $coverage->policies(array_keys($chunk), $today);
            foreach ($chunk as $id => $e) {
                if (! isset($have[$id]) && $e->joined_on <= $today) {
                    yield new Finding($id, 'emp:'.$id, 'No attendance policy applies', $e->display_name.' has no published attendance policy assigned, so attendance cannot be calculated by rule. Assign a policy to the employee, their department or the company.',
                        'warning', ['employee', $id], ['fix' => 'Assign an attendance policy'], Classification::MANAGER_SENSITIVE);
                }
            }
        }
    }

    public function noShift(int $tenantId): iterable
    {
        $today = Ops::today();
        $coverage = new Coverage;
        foreach ($this->chunks($tenantId) as $chunk) {
            $states = $coverage->shiftStates(array_keys($chunk), $today);
            foreach ($chunk as $id => $e) {
                if (($states[$id]['state'] ?? '') === 'unassigned' && $e->joined_on <= $today) {
                    yield new Finding($id, 'emp:'.$id, 'No shift is assigned', $e->display_name.' has no shift from a roster, a shift assignment or a rotation today. Assign a shift or publish the roster.',
                        'warning', ['employee', $id], ['reason' => $states[$id]['reason']], Classification::MANAGER_SENSITIVE);
                }
            }
        }
    }

    public function invalidAssignments(int $tenantId): iterable
    {
        $shifts = DB::table('shifts')->where('tenant_id', $tenantId)->get(['id', 'active'])->keyBy('id');
        $rotations = DB::table('shift_rotations')->where('tenant_id', $tenantId)->get(['id', 'active', 'cycle_length'])->keyBy('id');
        $patternCounts = DB::table('shift_rotation_patterns')->where('tenant_id', $tenantId)->selectRaw('rotation_id, count(*) as c')->groupBy('rotation_id')->pluck('c', 'rotation_id');
        foreach (DB::table('shift_assignments')->where('tenant_id', $tenantId)->orderBy('id')->cursor() as $a) {
            $problems = [];
            if (! $a->shift_id && ! $a->rotation_id) {
                $problems[] = 'it has neither a shift nor a rotation';
            }
            if ($a->shift_id && $a->rotation_id) {
                $problems[] = 'it has both a shift and a rotation';
            }
            if ($a->shift_id && (! isset($shifts[$a->shift_id]) || ! $shifts[$a->shift_id]->active)) {
                $problems[] = 'its shift is missing or inactive';
            }
            if ($a->effective_to && $a->effective_to < $a->effective_from) {
                $problems[] = 'it ends before it starts';
            }
            if ($a->rotation_id) {
                $rotation = $rotations[$a->rotation_id] ?? null;
                if (! $rotation || ! $rotation->active) {
                    $problems[] = 'its rotation is missing or inactive';
                } elseif ((int) ($patternCounts[$a->rotation_id] ?? 0) !== (int) $rotation->cycle_length) {
                    $problems[] = 'its rotation does not define every day of the cycle';
                }
                if (! $a->rotation_anchor) {
                    $problems[] = 'its rotation has no start date';
                }
            }
            if ($a->target_type === 'employee' && ! DB::table('employees')->where('tenant_id', $tenantId)->where('id', (int) $a->target_id)->exists()) {
                $problems[] = 'its employee no longer exists';
            }
            if ($problems) {
                yield new Finding($a->target_type === 'employee' ? (int) $a->target_id : null, 'assign:'.$a->id, 'A shift assignment is not valid', 'The shift assignment for '.$a->target_type.' '.$a->target_id.' from '.$a->effective_from.' is not valid: '.implode('; ', $problems).'.',
                    'warning', ['shift_assignment', (int) $a->id], ['problems' => $problems], Classification::HR_CONFIDENTIAL);
            }
        }
    }

    public function overlappingAssignments(int $tenantId): iterable
    {
        $groups = DB::table('shift_assignments')->where('tenant_id', $tenantId)->where('is_override', false)->orderBy('effective_from')->get()->groupBy(fn ($a) => $a->target_type.'|'.$a->target_id);
        foreach ($groups as $rows) {
            $rows = $rows->values();
            foreach ($rows as $i => $a) {
                for ($j = $i + 1; $j < $rows->count(); $j++) {
                    $b = $rows[$j];
                    $aEnd = $a->effective_to ?: '9999-12-31';
                    $bEnd = $b->effective_to ?: '9999-12-31';
                    if ((int) $a->priority === (int) $b->priority && $a->effective_from <= $bEnd && $b->effective_from <= $aEnd) {
                        $low = min($a->id, $b->id);
                        $high = max($a->id, $b->id);
                        yield new Finding($a->target_type === 'employee' ? (int) $a->target_id : null, 'overlap:'.$low.':'.$high, 'Shift assignments overlap',
                            'Two shift assignments for '.$a->target_type.' '.$a->target_id.' cover the same dates with the same priority ('.$a->effective_from.' to '.($a->effective_to ?: 'open').' and '.$b->effective_from.' to '.($b->effective_to ?: 'open').'). End one of them or give one a higher priority.',
                            'warning', ['shift_assignment', (int) $low], ['assignment_ids' => [(int) $low, (int) $high]], Classification::HR_CONFIDENTIAL);
                    }
                }
            }
        }
    }

    public function deviceMappings(int $tenantId): iterable
    {
        $today = Ops::today();
        $since = CarbonImmutable::parse($today)->subDays(7)->toDateString().' 00:00:00';
        $unmapped = DB::table('device_sync_runs as r')->join('attendance_devices as d', 'd.id', '=', 'r.device_id')->where('r.tenant_id', $tenantId)->where('r.started_at', '>=', $since)
            ->groupBy('d.id', 'd.name')->havingRaw('sum(r.unmapped) > 0')->selectRaw('d.id, d.name, sum(r.unmapped) as unmapped')->get();
        foreach ($unmapped as $d) {
            yield new Finding(null, 'dev:'.$d->id, 'Device events without an employee mapping', 'Device "'.$d->name.'" reported '.(int) $d->unmapped.' event(s) in the last 7 days that match no employee. Map the device user ids to employees.',
                'warning', ['attendance_device', (int) $d->id], ['unmapped_events' => (int) $d->unmapped], Classification::HR_CONFIDENTIAL);
        }
        $locations = DB::table('attendance_devices')->where('tenant_id', $tenantId)->where('status', 'active')->whereNotNull('location_id')->whereIn('type', ['biometric', 'rfid', 'face'])->pluck('location_id')->unique()->all();
        if (! $locations) {
            return;
        }
        $rows = DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')->join('employee_assignments as a', function ($j) use ($today) {
            $j->on('a.employee_id', '=', 'e.id')->where('a.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $today));
        })->where('e.tenant_id', $tenantId)->whereNull('e.deleted_at')->whereIn('e.status', Ops::WORKING)->whereIn('a.location_id', $locations)
            ->whereNotExists(function ($s) use ($today) {
                $s->from('employee_device_mappings as m')->whereColumn('m.employee_id', 'e.id')->where('m.effective_from', '<=', $today)->where(fn ($w) => $w->whereNull('m.effective_to')->orWhere('m.effective_to', '>=', $today));
            })->orderBy('e.id')->get(['e.id', 'p.display_name']);
        foreach ($rows as $e) {
            yield new Finding((int) $e->id, 'emp:'.$e->id, 'No device mapping', $e->display_name.' works at a location with a biometric device but is not mapped to any device user, so device punches cannot be matched.',
                'info', ['employee', (int) $e->id], [], Classification::MANAGER_SENSITIVE);
        }
    }

    public function timezones(int $tenantId): iterable
    {
        $since = CarbonImmutable::parse(Ops::today())->subDays(14)->toDateString();
        $rows = DB::table('attendance_events as ev')->join('employees as e', 'e.id', '=', 'ev.employee_id')->join('people as p', 'p.id', '=', 'e.person_id')->leftJoin('attendance_devices as d', 'd.id', '=', 'ev.device_id')
            ->where('ev.tenant_id', $tenantId)->where('ev.local_date', '>=', $since)->whereNull('ev.voided_at')->whereRaw('ev.timezone <> coalesce(d.timezone, e.timezone)')
            ->select('e.id', 'p.display_name', 'ev.timezone', DB::raw('coalesce(d.timezone, e.timezone) as expected'))->orderBy('e.id')->get()->groupBy('id');
        foreach ($rows as $id => $events) {
            $first = $events->first();
            yield new Finding((int) $id, 'emp:'.$id, 'Punch time zone differs from the expected zone', $events->count().' punch(es) in the last 14 days were recorded in '.$first->timezone.' while '.$first->expected.' was expected. This can be legitimate (travel); check the device or employee time zone.',
                'warning', ['employee', (int) $id], ['punches' => $events->count(), 'recorded_zone' => $first->timezone, 'expected_zone' => $first->expected], Classification::HR_CONFIDENTIAL);
        }
    }

    public function duplicates(int $tenantId): iterable
    {
        $since = CarbonImmutable::parse(Ops::today())->subDays(7)->toDateString().' 00:00:00';
        $rows = DB::table('device_sync_runs as r')->join('attendance_devices as d', 'd.id', '=', 'r.device_id')->where('r.tenant_id', $tenantId)->where('r.started_at', '>=', $since)
            ->groupBy('d.id', 'd.name')->selectRaw('d.id, d.name, sum(r.duplicates) as dup, sum(r.inserted) as ins')->get();
        foreach ($rows as $d) {
            if ((int) $d->dup >= 20 && (int) $d->dup > (int) $d->ins) {
                yield new Finding(null, 'dev:'.$d->id, 'Excessive duplicate device events', 'Device "'.$d->name.'" delivered '.(int) $d->dup.' duplicate event(s) against '.(int) $d->ins.' new ones in the last 7 days. Check its sync settings or clock.',
                    'warning', ['attendance_device', (int) $d->id], ['duplicates' => (int) $d->dup, 'new_events' => (int) $d->ins], Classification::HR_CONFIDENTIAL);
            }
        }
    }

    public function missingPunches(int $tenantId): iterable
    {
        $cutoff = CarbonImmutable::parse(Ops::today())->subDays(2)->toDateString();
        $rows = DB::table('attendance_days as d')->join('employees as e', 'e.id', '=', 'd.employee_id')->join('people as p', 'p.id', '=', 'e.person_id')->where('d.tenant_id', $tenantId)
            ->where('d.missing_punch', true)->where('d.finalization', 'open')->where('d.attendance_date', '<=', $cutoff)->orderBy('d.id')->select('d.id', 'd.employee_id', 'd.attendance_date', 'p.display_name')->cursor();
        foreach ($rows as $d) {
            yield new Finding((int) $d->employee_id, 'day:'.$d->employee_id.':'.$d->attendance_date, 'A missing punch is still unresolved', 'The '.$d->attendance_date.' attendance of '.$d->display_name.' has had a missing punch for more than 24 hours. Add the punch or approve a regularization so the day can be finalized.',
                'action_required', ['attendance_day', (int) $d->id], ['date' => $d->attendance_date], Classification::MANAGER_SENSITIVE);
        }
    }

    public function notProcessed(int $tenantId): iterable
    {
        $today = Ops::today();
        $coverage = new Coverage;
        $dates = array_map(fn ($i) => CarbonImmutable::parse($today)->subDays($i)->toDateString(), [3, 2, 1]);
        foreach ($this->chunks($tenantId) as $chunk) {
            $missing = [];
            foreach ($dates as $date) {
                $have = DB::table('attendance_days')->where('tenant_id', $tenantId)->where('attendance_date', $date)->whereIn('employee_id', array_keys($chunk))->pluck('employee_id')->flip();
                $rest = array_values(array_filter(array_keys($chunk), fn ($id) => ! isset($have[$id]) && $chunk[$id]->joined_on <= $date));
                foreach ($coverage->shiftStates($rest, $date) as $id => $state) {
                    if ($state['state'] === 'working') {
                        $missing[$id][] = $date;
                    }
                }
            }
            foreach ($missing as $id => $list) {
                yield new Finding($id, 'emp:'.$id, 'Attendance has not been processed', $chunk[$id]->display_name.' was scheduled to work on '.implode(', ', $list).' but no attendance has been calculated for '.(count($list) === 1 ? 'that day' : 'those days').'. Run processing for the employee.',
                    'warning', ['employee', $id], ['dates' => $list], Classification::MANAGER_SENSITIVE);
            }
        }
    }

    public function unlockedNearCutoff(int $tenantId): iterable
    {
        $state = PayrollCutoff::state(Ops::today(), PayrollCutoff::day());
        if (! $state['configured'] || ! in_array($state['status'], ['at_risk_window', 'passed'], true)) {
            return;
        }
        $query = DB::table('attendance_days')->where('tenant_id', $tenantId)->whereBetween('attendance_date', [$state['period_from'], $state['period_to']])->where('finalization', '!=', 'locked');
        $days = (clone $query)->count();
        if ($days === 0) {
            return;
        }
        $passed = $state['status'] === 'passed';
        yield new Finding(null, 'cutoff:'.$state['period_from'], 'Attendance is not locked '.($passed ? 'after' : 'close to').' the payroll cutoff',
            $days.' employee-day(s) of '.$state['period_from'].' to '.$state['period_to'].' are not locked and the payroll cutoff '.($passed ? 'was' : 'is').' '.$state['cutoff_date'].'. Resolve the remaining exceptions and lock the period.',
            $passed ? 'critical' : 'action_required', null, ['employee_days' => $days, 'cutoff_date' => $state['cutoff_date'], 'employees' => (clone $query)->distinct()->count('employee_id')], Classification::HR_CONFIDENTIAL);
    }

    public function holidayCalendars(int $tenantId): iterable
    {
        $year = CarbonImmutable::parse(Ops::today())->year;
        $calendars = DB::table('holiday_calendars')->where('tenant_id', $tenantId)->where('active', true)->pluck('id');
        $withHolidays = $calendars->isEmpty() ? 0 : DB::table('holidays')->where('tenant_id', $tenantId)->whereIn('calendar_id', $calendars)->whereBetween('date', [$year.'-01-01', $year.'-12-31'])->count();
        if ($withHolidays === 0) {
            yield new Finding(null, 'tenant:'.$year, 'No holiday calendar for '.$year, 'No active holiday calendar has holidays for '.$year.'. Holidays will be treated as working days until one is added.', 'warning', null, ['year' => $year], Classification::HR_CONFIDENTIAL);

            return;
        }
        $today = Ops::today();
        $coverage = new Coverage;
        $uncovered = [];
        foreach ($this->chunks($tenantId) as $chunk) {
            $ids = array_keys($chunk);
            $have = $coverage->calendars($ids, $today);
            $countries = DB::table('employees')->where('tenant_id', $tenantId)->whereIn('id', $ids)->pluck('country', 'id');
            foreach ($ids as $id) {
                if (! isset($have[$id])) {
                    $uncovered[$countries[$id] ?? '??'] = ($uncovered[$countries[$id] ?? '??'] ?? 0) + 1;
                }
            }
        }
        foreach ($uncovered as $country => $count) {
            yield new Finding(null, 'country:'.$country, 'Employees in '.$country.' have no holiday calendar', $count.' employee(s) in '.$country.' have no holiday calendar assigned, so public holidays will not be recognised for them.',
                'warning', null, ['country' => $country, 'employees' => $count], Classification::HR_CONFIDENTIAL);
        }
    }
}
