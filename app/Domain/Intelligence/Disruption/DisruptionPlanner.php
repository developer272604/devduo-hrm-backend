<?php

namespace App\Domain\Intelligence\Disruption;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Learning\Talent\TalentService;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operational Disruption Recovery Planner. For a date window it reads approved leave and known exits (resignations and notice-period last days),
 * shows by department which days fall below a chosen availability, and for each absent person suggests cover in a fixed order of trust:
 * the delegate named on the leave request, the backups recorded in knowledge continuity, then colleagues whose recorded skills fit and who are
 * free for the whole absence. Leave type and reason are never read. Only people inside the viewer's data scope are considered.
 */
final class DisruptionPlanner
{
    public const MAX_DAYS = 62;

    private const MAX_EMPLOYEES = 2000;

    private const LEGACY_RANK = ['beginner' => 1, 'intermediate' => 2, 'advanced' => 3, 'expert' => 4];

    public function __construct(private DataScope $scope, private TalentService $talent) {}

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function plan(User $u, string $from, string $to, int $minAvailablePct = 80): array
    {
        foreach (['from' => $from, 'to' => $to] as $k => $v) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
                $this->fail($k, 'Use a valid date (YYYY-MM-DD).');
            }
        }
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        if ($end->lt($start) || $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $this->fail('to', 'Choose a window of 1 to '.self::MAX_DAYS.' days.');
        }
        if ($minAvailablePct < 1 || $minAvailablePct > 100) {
            $this->fail('min_available_pct', 'Choose a percentage from 1 to 100.');
        }
        $tenant = $u->tenant_id;
        $q = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $tenant)->whereNull('employees.deleted_at')
            ->whereIn('employees.status', Employee::WORKING)->where('employees.joined_on', '<=', $end->toDateString());
        $emps = $this->scope->constrain($q, $u, 'intelligence.disruption.view')->limit(self::MAX_EMPLOYEES + 1)->get(['employees.id', 'employees.uuid', 'employees.person_id', 'employees.joined_on', 'people.display_name as name']);
        if ($emps->count() > self::MAX_EMPLOYEES) {
            $this->fail('scope', 'Your scope has more than '.self::MAX_EMPLOYEES.' people. Narrow the window or ask for a department-level view.');
        }
        $ids = $emps->pluck('id')->all();
        $dept = DB::table('employee_assignments')->where('tenant_id', $tenant)->whereIn('employee_id', $ids)->where('effective_from', '<=', $end->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start->toDateString()))->orderBy('effective_from')->get(['employee_id', 'department_id'])->keyBy('employee_id');
        $deptNames = DB::table('departments')->where('tenant_id', $tenant)->pluck('name', 'id');

        // Absences: approved leave overlapping the window, and known exits (absent from the day after the last working day).
        $absent = []; // employee id => list of [from, to, kind, delegate_employee_id]
        $leaves = DB::table('leave_requests')->where('tenant_id', $tenant)->whereIn('employee_id', $ids)->where('status', 'approved')
            ->where('start_date', '<=', $end->toDateString())->where('end_date', '>=', $start->toDateString())->get(['employee_id', 'start_date', 'end_date', 'delegate_employee_id']);
        foreach ($leaves as $l) {
            $absent[$l->employee_id][] = ['from' => max($l->start_date, $start->toDateString()), 'to' => min($l->end_date, $end->toDateString()), 'kind' => 'leave', 'delegate' => $l->delegate_employee_id];
        }
        foreach ($this->exits($tenant, $ids) as $eid => $last) {
            $gone = CarbonImmutable::parse($last)->addDay();
            if ($gone->lte($end)) {
                $absent[$eid][] = ['from' => max($gone->toDateString(), $start->toDateString()), 'to' => $end->toDateString(), 'kind' => 'exit', 'delegate' => null];
            }
        }
        $absentOn = function (int $eid, string $day) use ($absent) {
            foreach ($absent[$eid] ?? [] as $a) {
                if ($day >= $a['from'] && $day <= $a['to']) {
                    return true;
                }
            }

            return false;
        };

        // Availability by department and day.
        $byDept = $emps->groupBy(fn ($e) => $dept[$e->id]->department_id ?? 0);
        $departments = [];
        foreach ($byDept as $deptId => $members) {
            $worst = null;
            $short = [];
            for ($d = $start; $d->lte($end); $d = $d->addDay()) {
                if ($d->isWeekend()) {
                    continue;
                }
                $day = $d->toDateString();
                $here = $members->filter(fn ($e) => $e->joined_on <= $day);
                if ($here->isEmpty()) {
                    continue;
                }
                $away = $here->filter(fn ($e) => $absentOn($e->id, $day))->count();
                $pct = round(($here->count() - $away) / $here->count() * 100, 1);
                if ($worst === null || $pct < $worst['available_pct']) {
                    $worst = ['date' => $day, 'available_pct' => $pct, 'away' => $away, 'of' => $here->count()];
                }
                if ($pct < $minAvailablePct) {
                    $short[] = ['date' => $day, 'available_pct' => $pct, 'away' => $away, 'of' => $here->count()];
                }
            }
            $departments[] = ['department' => $deptId ? ($deptNames[$deptId] ?? 'Unnamed department') : 'No department', 'headcount' => $members->count(), 'worst_day' => $worst, 'shortage_days' => $short, 'shortage_day_count' => count($short)];
        }
        usort($departments, fn ($a, $b) => [$b['shortage_day_count'], $a['worst_day']['available_pct'] ?? 100] <=> [$a['shortage_day_count'], $b['worst_day']['available_pct'] ?? 100]);

        // Cover suggestions for each absence (largest shortages first, capped).
        $skills = $this->skills($tenant, $emps->pluck('person_id')->all());
        $skillNames = DB::table('skills')->where('tenant_id', $tenant)->pluck('name', 'id');
        $personOf = $emps->keyBy('id');
        $shortDepts = collect($departments)->filter(fn ($d) => $d['shortage_day_count'] > 0)->pluck('department')->all();
        $responsibilities = DB::table('continuity_responsibilities')->where('tenant_id', $tenant)->whereIn('owner_employee_id', array_keys($absent))->where('status', 'active')->get()->groupBy('owner_employee_id');
        $names = $emps->pluck('name', 'id');
        $uuids = $emps->pluck('uuid', 'id');
        $absences = [];
        foreach ($absent as $eid => $spans) {
            $deptName = ($dept[$eid]->department_id ?? 0) ? ($deptNames[$dept[$eid]->department_id] ?? 'Unnamed department') : 'No department';
            $e = $personOf[$eid];
            $mine = $skills[$e->person_id] ?? [];
            arsort($mine);
            $top = array_slice($mine, 0, 5, true);
            foreach ($spans as $span) {
                $cands = [];
                foreach ($emps as $c) {
                    if ($c->id === $eid || ($dept[$c->id]->department_id ?? 0) !== ($dept[$eid]->department_id ?? 0)) {
                        continue;
                    }
                    if ($this->overlaps($absent[$c->id] ?? [], $span['from'], $span['to'])) {
                        continue; // not free for the whole absence
                    }
                    $cs = $skills[$c->person_id] ?? [];
                    $cov = $top ? array_sum(array_map(fn ($sid, $rank) => min(1, ($cs[$sid] ?? 0) / max(1, $rank)), array_keys($top), $top)) / count($top) : 0.0;
                    if ($top && $cov <= 0) {
                        continue;
                    }
                    $cands[] = ['employee' => ['uuid' => $c->uuid, 'name' => $c->name], 'skill_fit' => round($cov, 2), 'covers' => array_values(array_map(fn ($sid) => $skillNames[$sid] ?? '', array_filter(array_keys($top), fn ($sid) => ($cs[$sid] ?? 0) >= $top[$sid]))),
                        'free_hours' => $this->freeHours($c->id)];
                }
                usort($cands, fn ($a, $b) => [$b['skill_fit'], $b['free_hours'] ?? -1, $a['employee']['name']] <=> [$a['skill_fit'], $a['free_hours'] ?? -1, $b['employee']['name']]);
                $resp = $responsibilities[$eid] ?? collect();
                $absences[] = ['employee' => ['uuid' => $uuids[$eid], 'name' => $names[$eid]], 'department' => $deptName, 'kind' => $span['kind'], 'from' => $span['from'], 'to' => $span['to'], 'in_shortage_department' => in_array($deptName, $shortDepts, true),
                    'delegate' => $span['delegate'] && isset($names[$span['delegate']]) ? ['uuid' => $uuids[$span['delegate']], 'name' => $names[$span['delegate']]] : null,
                    'continuity_backups' => $resp->filter(fn ($r) => $r->backup_employee_id && $r->backup_trained)->map(fn ($r) => ['responsibility' => $r->title, 'criticality' => $r->criticality, 'backup' => ['uuid' => DB::table('employees')->where('id', $r->backup_employee_id)->value('uuid'),
                        'name' => DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $r->backup_employee_id)->value('people.display_name')]])->values()->all(),
                    'uncovered_responsibilities' => $resp->filter(fn ($r) => ! $r->backup_employee_id || ! $r->backup_trained)->map(fn ($r) => ['responsibility' => $r->title, 'criticality' => $r->criticality])->values()->all(),
                    'key_skills' => array_values(array_map(fn ($sid, $rank) => ['skill' => $skillNames[$sid] ?? '', 'rank' => $rank], array_keys($top), $top)), 'candidates' => array_slice($cands, 0, 5)];
            }
        }
        usort($absences, fn ($a, $b) => [! $a['in_shortage_department'], $a['from']] <=> [! $b['in_shortage_department'], $b['from']]);
        Audit::record('disruption.plan', 'intelligence', 'user:'.$u->id, ['from' => $from, 'to' => $to, 'absences' => count($absences)]);

        return ['window' => ['from' => $from, 'to' => $to], 'min_available_pct' => $minAvailablePct, 'people_in_scope' => $emps->count(), 'departments' => $departments, 'absences' => array_slice($absences, 0, 100),
            'method' => 'Availability = working employees not on approved leave or past their last working day, per department and weekday. Cover is suggested in order: the delegate on the leave request, trained backups from knowledge continuity, then same-department colleagues who are free for the whole absence, ranked by how many of the absent person\'s top skills they hold.',
            'limitations' => 'Only approved leave and known exits are counted, so unplanned absence is not predicted. Leave type and reason are not read. Skill fit uses recorded levels. Free hours appear only for people who declared spare capacity in the talent marketplace. Suggestions are for a manager to decide, they are not assignments.'];
    }

    private function overlaps(array $spans, string $from, string $to): bool
    {
        foreach ($spans as $s) {
            if ($s['from'] <= $to && $s['to'] >= $from) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int,string> employee id => last working day (today or later) */
    private function exits(int $tenant, array $ids): array
    {
        $today = TenantClock::today();
        $out = [];
        foreach (DB::table('lifecycle_resignations')->where('tenant_id', $tenant)->whereIn('employee_id', $ids)->whereIn('status', ['pending', 'approved'])->where('requested_last_day', '>=', $today)->get(['employee_id', 'requested_last_day']) as $r) {
            $out[$r->employee_id] = $r->requested_last_day;
        }
        foreach (DB::table('employees')->where('tenant_id', $tenant)->whereIn('id', $ids)->where('status', 'notice_period')->whereNotNull('separated_on')->where('separated_on', '>=', $today)->get(['id', 'separated_on']) as $e) {
            if (! isset($out[$e->id]) || $e->separated_on < $out[$e->id]) {
                $out[$e->id] = $e->separated_on;
            }
        }

        return $out;
    }

    /** @return array<int,array<int,int>> person id => [skill id => rank] (rejected claims are ignored) */
    private function skills(int $tenant, array $personIds): array
    {
        $out = [];
        foreach (DB::table('employee_skills')->where('tenant_id', $tenant)->whereIn('person_id', $personIds)->where('verification_status', '!=', 'rejected')->get(['person_id', 'skill_id', 'level_rank', 'proficiency']) as $s) {
            $out[$s->person_id][$s->skill_id] = (int) ($s->level_rank ?? (self::LEGACY_RANK[$s->proficiency] ?? 0));
        }

        return $out;
    }

    /** @var array<int,?int> */
    private array $freeCache = [];

    private function freeHours(int $employeeId): ?int
    {
        if (! array_key_exists($employeeId, $this->freeCache)) {
            $spare = DB::table('talent_profiles')->where('employee_id', $employeeId)->value('spare_hours_per_week');
            $this->freeCache[$employeeId] = $spare === null ? null : max(0, (int) $spare - $this->talent->committedHours($employeeId));
        }

        return $this->freeCache[$employeeId];
    }
}
