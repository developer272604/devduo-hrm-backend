<?php

namespace App\Domain\Analytics;

use App\Domain\Access\DataScope;
use App\Domain\Analytics\Models\AccessLog;
use App\Domain\Analytics\Models\RefreshRun;
use App\Domain\Analytics\Models\Scenario;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AnalyticsService
{
    public const COHORT = 5;

    public const CATALOG = [
        'headcount' => ['label' => 'As-of headcount', 'permission' => 'people.view', 'module' => 'people', 'unit' => 'employees', 'formula' => 'Working employments at period end, using effective-dated status history.', 'source' => 'employees + employee_status_history'],
        'hires' => ['label' => 'Hires', 'permission' => 'people.view', 'module' => 'people', 'unit' => 'employments', 'formula' => 'Employment joining dates within the inclusive period; rehires are new employments.', 'source' => 'employees.joined_on'],
        'leavers' => ['label' => 'Leavers', 'permission' => 'people.view', 'module' => 'people', 'unit' => 'employments', 'formula' => 'Employment separation dates within the inclusive period.', 'source' => 'employees.separated_on'],
        'attrition' => ['label' => 'Period attrition', 'permission' => 'people.view', 'module' => 'people', 'unit' => 'percent', 'formula' => '100 × leavers / mean(opening, closing headcount). Not annualized. Null when denominator is zero.', 'source' => 'employees + employee_status_history'],
        'tenure' => ['label' => 'Average tenure', 'permission' => 'people.view', 'module' => 'people', 'unit' => 'days', 'formula' => 'Mean days since this employment joining date for working employments at period end.', 'source' => 'employees.joined_on + employee_status_history'],
        'overtime' => ['label' => 'Approved overtime', 'permission' => 'attendance.reports.view', 'module' => 'attendance', 'unit' => 'minutes', 'formula' => 'Sum approved overtime minutes in confirmed, approved or locked attendance days. Unfinalized days excluded.', 'source' => 'attendance_days.approved_overtime_minutes'],
        'absence' => ['label' => 'Absence rate', 'permission' => 'attendance.reports.view', 'module' => 'attendance', 'unit' => 'percent', 'formula' => '100 × absent days / scheduled days, among confirmed approved/locked attendance rows with required_minutes > 0.', 'source' => 'attendance_days'],
        'learning_completions' => ['label' => 'Learning completions', 'permission' => 'learning.reports.view', 'module' => 'learning', 'unit' => 'completions', 'formula' => 'Completed enrolments with completed_at in the period, interpreted in the workspace timezone.', 'source' => 'learning_enrolments.completed_at'],
        'leave_utilization' => ['label' => 'Consumed leave units', 'permission' => 'leave.reports.view', 'module' => 'leave', 'unit' => 'leave units', 'formula' => 'Sum posted consumption ledger units by effective date. Pending reservations are excluded.', 'source' => 'leave_ledger_entries.entry_type + units + effective_date'],
        'recruitment_pipeline' => ['label' => 'Recruitment pipeline', 'permission' => 'recruitment.reports.view', 'module' => 'recruitment', 'unit' => 'applications', 'formula' => 'Current application counts by stage for applications whose stage_entered_at falls in the period. External candidate records are never returned.', 'source' => 'recruitment_applications.stage + stage_entered_at'],
        'performance_status' => ['label' => 'Finalized performance reviews', 'permission' => 'performance.reports.view', 'module' => 'performance', 'unit' => 'reviews', 'formula' => 'Reviews finalized in the selected period, restricted to currently authorized employees.', 'source' => 'performance_reviews.status + finalized_at + employee_id'],
        'ticket_sla' => ['label' => 'Helpdesk SLA attainment', 'permission' => 'experience.analytics.view', 'module' => 'experience', 'unit' => 'percent', 'formula' => 'Resolved tickets with a first response at or before sla_due_at divided by resolved tickets with an SLA and a first response.', 'source' => 'experience_tickets.sla_due_at + first_response_at + resolved_at'],
        'payroll_totals' => ['label' => 'Published payroll totals', 'permission' => 'payroll.reports.view', 'module' => 'payroll', 'unit' => 'currency amount', 'formula' => 'Gross and net totals by currency for locked or closed payroll results in the period and within current employee scope.', 'source' => 'payroll_runs + payroll_periods + payroll_calculation_results'],
    ];

    public function catalog(User $u): array
    {
        return collect(self::CATALOG)->filter(fn ($m) => $u->allows($m['permission']) && in_array($m['module'], $u->tenant->settings['modules'] ?? [], true))
            ->map(fn ($m, $key) => ['key' => $key, 'version' => 1, 'owner' => $m['module'], 'cadence' => 'on demand', 'minimum_cohort' => self::COHORT,
                'dimensions' => in_array($key, ['headcount', 'tenure']) ? ['total', 'department', 'location', 'legal_entity'] : ['total']] + $m)->values()->all();
    }

    public function validateSpec(array $input): array
    {
        $d = validator($input, ['metric' => 'required|in:'.implode(',', array_keys(self::CATALOG)),
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'dimension' => 'required|in:total,department,location,legal_entity'])->validate();
        if ($d['to'] > TenantClock::today() || CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) > 366) {
            $this->invalid('Choose a historical period of at most 367 inclusive days.');
        }
        if (! in_array($d['metric'], ['headcount', 'tenure']) && $d['dimension'] !== 'total') {
            $this->invalid('This metric supports totals only.');
        }

        return $d;
    }

    public function query(User $u, array $input, bool $persist = true, string $action = 'query'): array
    {
        abort_unless($u->allows('analytics.view'), 403);
        $d = $this->validateSpec($input);
        $metric = self::CATALOG[$d['metric']];
        abort_unless($u->allows($metric['permission']) && in_array($metric['module'], $u->tenant->settings['modules'] ?? [], true), 403);

        return DB::transaction(function () use ($u, $d, $metric, $persist, $action) {
            $employees = app(DataScope::class)->constrain(Employee::query(), $u, $metric['permission'])->limit(10001)->get(['id', 'joined_on', 'separated_on']);
            if ($employees->count() > 10000) {
                $this->invalid('This synchronous report supports at most 10,000 scoped employments.');
            }
            $ids = $employees->pluck('id');
            $statuses = DB::table('employee_status_history')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $ids)->where('effective_date', '<=', $d['to'])->orderBy('effective_date')->orderBy('id')->get(['employee_id', 'to_status', 'effective_date']);
            $atEnd = $statuses->groupBy('employee_id')->map(fn ($rows) => $rows->last()->to_status);
            $atStart = $statuses->where('effective_date', '<', $d['from'])->groupBy('employee_id')->map(fn ($rows) => $rows->last()->to_status);
            $assignments = DB::table('employee_assignments')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $ids)->where('effective_from', '<=', $d['to'])
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $d['to']))->orderBy('effective_from')->orderBy('id')->get();
            $overlaps = $assignments->groupBy('employee_id')->filter(fn ($rows) => $rows->count() > 1)->count();
            $assignmentByEmployee = $assignments->keyBy('employee_id');
            $groups = $d['dimension'] === 'total' ? collect(['total' => $employees]) : $employees->groupBy(fn ($e) => $assignmentByEmployee->get($e->id)?->{$d['dimension'].'_id'} ?? 'unassigned');
            if ($groups->count() > 100) {
                $this->invalid('The report exceeds the 100-group limit.');
            }
            $labels = [];
            if ($d['dimension'] !== 'total') {
                $table = ['department' => 'departments', 'location' => 'locations', 'legal_entity' => 'legal_entities'][$d['dimension']];
                $labels = DB::table($table)->where('tenant_id', $u->tenant_id)->pluck('name', 'id')->all();
            }
            $rows = [];
            $suppressed = false;
            foreach ($groups as $group => $members) {
                $end = $members->filter(fn ($e) => in_array($atEnd->get($e->id), Employee::WORKING, true) && $e->joined_on->toDateString() <= $d['to']);
                $opening = $members->filter(fn ($e) => in_array($atStart->get($e->id), Employee::WORKING, true) && $e->joined_on->toDateString() < $d['from'])->count();
                $hires = $members->filter(fn ($e) => $e->joined_on->toDateString() >= $d['from'] && $e->joined_on->toDateString() <= $d['to'])->count();
                $leavers = $members->filter(fn ($e) => $e->separated_on && $e->separated_on->toDateString() >= $d['from'] && $e->separated_on->toDateString() <= $d['to'])->count();
                $numerator = 0;
                $denominator = null;
                $cohort = $members->count();
                $smallEvent = false;
                switch ($d['metric']) {
                    case 'headcount': $numerator = $end->count();
                        $cohort = $end->count();
                        break;
                    case 'hires': $numerator = $hires;
                        $smallEvent = $hires > 0 && $hires < self::COHORT;
                        break;
                    case 'leavers': $numerator = $leavers;
                        $smallEvent = $leavers > 0 && $leavers < self::COHORT;
                        break;
                    case 'attrition':
                        $numerator = $leavers;
                        $denominator = ($opening + $end->count()) / 2;
                        $smallEvent = ($leavers > 0 && $leavers < self::COHORT) || ($opening > 0 && $opening < self::COHORT) || ($end->count() > 0 && $end->count() < self::COHORT);
                        break;
                    case 'tenure':
                        $numerator = $end->sum(fn ($e) => (int) CarbonImmutable::parse($e->joined_on)->diffInDays(CarbonImmutable::parse($d['to'])));
                        $denominator = $end->count();
                        $cohort = $end->count();
                        break;
                    case 'overtime':
                    case 'absence':
                        $q = DB::table('attendance_days')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $members->pluck('id'))->whereBetween('attendance_date', [$d['from'], $d['to']])
                            ->where('confidence', 'confirmed')->whereIn('finalization', ['approved', 'locked']);
                        if ($d['metric'] === 'absence') {
                            $q->where('required_minutes', '>', 0);
                        }
                        $cohort = (clone $q)->distinct()->count('employee_id');
                        if ($d['metric'] === 'overtime') {
                            $numerator = (int) $q->sum('approved_overtime_minutes');
                        } else {
                            $denominator = (clone $q)->count();
                            $numerator = (clone $q)->where('status', 'absent')->count();
                            $smallEvent = $numerator > 0 && (clone $q)->where('status', 'absent')->distinct()->count('employee_id') < self::COHORT;
                        }
                        break;
                    case 'learning_completions':
                        $timezone = $u->tenant->settings['timezone'] ?? 'UTC';
                        $q = DB::table('learning_enrolments')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $members->pluck('id'))->where('status', 'completed')
                            ->where('completed_at', '>=', CarbonImmutable::parse($d['from'], $timezone)->setTimezone(config('app.timezone')))
                            ->where('completed_at', '<', CarbonImmutable::parse($d['to'], $timezone)->addDay()->setTimezone(config('app.timezone')));
                        $numerator = $q->count();
                        $cohort = (clone $q)->distinct()->count('employee_id');
                        break;
                    case 'leave_utilization':
                        $q = DB::table('leave_ledger_entries')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $members->pluck('id'))
                            ->where('entry_type', 'consumption')->whereBetween('effective_date', [$d['from'], $d['to']]);
                        $numerator = abs((float) $q->sum('units'));
                        $cohort = (clone $q)->distinct()->count('employee_id');
                        break;
                    case 'recruitment_pipeline':
                        // Recruiter reports intentionally aggregate external applicants; never join or return candidate identity.
                        $pipeline = DB::table('recruitment_applications')->where('tenant_id', $u->tenant_id)
                            ->where('stage_entered_at', '>=', CarbonImmutable::parse($d['from'], $u->tenant->settings['timezone'] ?? 'UTC')->setTimezone(config('app.timezone')))
                            ->where('stage_entered_at', '<', CarbonImmutable::parse($d['to'], $u->tenant->settings['timezone'] ?? 'UTC')->addDay()->setTimezone(config('app.timezone')));
                        $counts = $pipeline->select('stage', DB::raw('COUNT(*) as total'))->groupBy('stage')->orderBy('stage')->get();
                        $cohort = (clone $pipeline)->distinct()->count('candidate_id');
                        $small = $counts->contains(fn ($row) => $row->total > 0 && $row->total < self::COHORT);
                        $rows = $counts->map(fn ($row) => ['group' => (string) $row->stage, 'value' => $small ? null : (int) $row->total,
                            'numerator' => $small ? null : (int) $row->total, 'denominator' => null, 'suppressed' => $small])->all();
                        $smallEvent = $small;
                        break;
                    case 'performance_status':
                        $q = DB::table('performance_reviews')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $members->pluck('id'))
                            ->whereIn('status', ['finalized', 'acknowledged'])
                            ->where('finalized_at', '>=', CarbonImmutable::parse($d['from'], $u->tenant->settings['timezone'] ?? 'UTC')->setTimezone(config('app.timezone')))
                            ->where('finalized_at', '<', CarbonImmutable::parse($d['to'], $u->tenant->settings['timezone'] ?? 'UTC')->addDay()->setTimezone(config('app.timezone')));
                        $numerator = $q->count(); $cohort = (clone $q)->distinct()->count('employee_id');
                        break;
                    case 'ticket_sla':
                        $q = DB::table('experience_tickets')->where('tenant_id', $u->tenant_id)->whereNotNull('resolved_at')->whereNotNull('sla_due_at')->whereNotNull('first_response_at')
                            ->where('resolved_at', '>=', CarbonImmutable::parse($d['from'], $u->tenant->settings['timezone'] ?? 'UTC')->setTimezone(config('app.timezone')))
                            ->where('resolved_at', '<', CarbonImmutable::parse($d['to'], $u->tenant->settings['timezone'] ?? 'UTC')->addDay()->setTimezone(config('app.timezone')));
                        $denominator = $q->count(); $numerator = (clone $q)->whereColumn('first_response_at', '<=', 'sla_due_at')->count();
                        $cohort = (clone $q)->distinct()->count('employee_id');
                        $onTimeCohort = (clone $q)->whereColumn('first_response_at', '<=', 'sla_due_at')->distinct()->count('employee_id');
                        $lateCohort = (clone $q)->whereColumn('first_response_at', '>', 'sla_due_at')->distinct()->count('employee_id');
                        $smallEvent = ($onTimeCohort > 0 && $onTimeCohort < self::COHORT) || ($lateCohort > 0 && $lateCohort < self::COHORT);
                        break;
                    case 'payroll_totals':
                        $base = DB::table('payroll_calculation_results as r')->join('payroll_runs as pr', 'pr.id', '=', 'r.run_id')->join('payroll_periods as pp', 'pp.id', '=', 'pr.period_id')
                            ->where('r.tenant_id', $u->tenant_id)->whereIn('r.employee_id', $members->pluck('id'))->where('r.is_current', true)->whereIn('r.status', ['ok','warning'])
                            ->whereIn('pr.status', ['locked','closed']);
                        $current = (clone $base)->whereBetween('pp.end_date', [$d['from'], $d['to']]);
                        $days = CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) + 1;
                        $previousFrom = CarbonImmutable::parse($d['from'])->subDays($days)->toDateString();
                        $previousTo = CarbonImmutable::parse($d['from'])->subDay()->toDateString();
                        $previous = (clone $base)->whereBetween('pp.end_date', [$previousFrom, $previousTo]);
                        $currencies = $current->select('r.currency', DB::raw('COUNT(DISTINCT r.employee_id) as cohort'), DB::raw('SUM(r.gross) as gross'), DB::raw('SUM(r.net) as net'))->groupBy('r.currency')->get()->keyBy('currency');
                        $previousCurrencies = $previous->select('r.currency', DB::raw('COUNT(DISTINCT r.employee_id) as cohort'), DB::raw('SUM(r.gross) as gross'), DB::raw('SUM(r.net) as net'))->groupBy('r.currency')->get()->keyBy('currency');
                        $cohort = $current->distinct()->count('r.employee_id');
                        $rows = $currencies->map(function ($row, $currency) use ($cohort, $previousCurrencies, $previousFrom, $previousTo) {
                            $prior = $previousCurrencies->get($currency);
                            $suppressed = $cohort < self::COHORT || $row->cohort < self::COHORT || ($prior && $prior->cohort > 0 && $prior->cohort < self::COHORT);
                            $percent = fn ($now, $before) => $before && (float)$before != 0.0 ? round(((float)$now - (float)$before) / abs((float)$before) * 100, 2) : null;
                            return ['group' => $currency, 'value' => $suppressed ? null : ['gross' => (string)$row->gross, 'net' => (string)$row->net,
                                'gross_change_percent' => $prior ? $percent($row->gross,$prior->gross) : null, 'net_change_percent' => $prior ? $percent($row->net,$prior->net) : null,
                                'previous_period' => ['from' => $previousFrom, 'to' => $previousTo]],
                                'numerator' => null, 'denominator' => null, 'suppressed' => $suppressed];
                        })->values()->all();
                        $smallEvent = $cohort < self::COHORT;
                        break;
                }
                $hide = ($cohort < self::COHORT) || $smallEvent;
                $suppressed = $suppressed || $hide;
                $value = $denominator === null ? $numerator : ($denominator > 0 ? round($numerator / $denominator * (in_array($d['metric'], ['attrition', 'absence']) ? 100 : 1), 2) : null);
                if (!in_array($d['metric'], ['recruitment_pipeline', 'payroll_totals'], true)) {
                    $rows[] = ['group' => $group === 'total' ? 'Total' : ($labels[$group] ?? 'Unassigned'), 'value' => $hide ? null : $value,
                        'numerator' => $hide ? null : $numerator, 'denominator' => $hide ? null : $denominator, 'suppressed' => $hide];
                }
            }
            // Suppress the whole breakdown if any cell is small; no remainder/total can reveal it.
            if ($suppressed && $d['dimension'] !== 'total') {
                $rows = [['group' => 'Breakdown withheld', 'value' => null, 'numerator' => null, 'denominator' => null, 'suppressed' => true]];
            }
            if (! $rows) {
                $rows = [['group' => 'Total', 'value' => null, 'numerator' => null, 'denominator' => null, 'suppressed' => true]];
            }
            $result = ['metric' => $d['metric'], 'version' => 1, 'label' => $metric['label'], 'unit' => $metric['unit'], 'definition' => $metric['formula'], 'lineage' => $metric['source'],
                'period' => ['from' => $d['from'], 'to' => $d['to']], 'dimension' => $d['dimension'], 'rows' => $rows, 'minimum_cohort' => self::COHORT,
                'refreshed_at' => now()->toIso8601String(), 'timezone' => $u->tenant->settings['timezone'] ?? 'UTC',
                'scope' => in_array($d['metric'], ['recruitment_pipeline', 'ticket_sla'], true)
                    ? 'Tenant aggregate authorized by the source report permission; no candidate or ticket identity is returned.'
                    : 'Current authorized employee scope; historical facts and effective-dated assignment at period end.',
                'quality' => ['overlapping_assignments' => $overlaps > 0, 'missing_status_history' => $ids->diff($atEnd->keys())->isNotEmpty()],
                'limitations' => 'Employment counts, not unique people or FTE. Organization labels use current names. Late source corrections affect reruns; no currency conversion. Suppressed cells are not zero.'];
            if ($overlaps && $d['dimension'] !== 'total') {
                $result['rows'] = [];
                $result['quality']['blocked'] = 'Repair overlapping effective assignments before using a breakdown.';
            }
            $missingHistory = $employees->filter(fn ($e) => $e->joined_on->toDateString() <= $d['to'] && ! $atEnd->has($e->id))->isNotEmpty();
            if ($missingHistory && in_array($d['metric'], ['headcount', 'tenure', 'attrition'])) {
                $result['rows'] = [];
                $result['quality']['blocked'] = 'Historical status records are missing. Repair source history before using this metric.';
            }
            if ($persist) {
                AccessLog::create(['actor_id' => $u->id, 'action' => $action, 'spec' => $d]);
                $run = RefreshRun::create(['actor_id' => $u->id, 'spec' => $d, 'result' => $result, 'status' => isset($result['quality']['blocked']) ? 'quality_blocked' : 'completed', 'refreshed_at' => now()]);
                $result['run_uuid'] = $run->uuid;
                Audit::record('analytics.'.$action, 'analytics', 'run:'.$run->uuid);
            }

            return $result;
        });
    }

    public function scenario(User $u, array $data): array
    {
        $d = validator($data, ['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d', 'hires' => 'required|integer|min:0|max:100000', 'leavers' => 'required|integer|min:0|max:100000'])->validate();
        $base = $this->query($u, ['metric' => 'headcount', 'dimension' => 'total', 'from' => $d['from'], 'to' => $d['to']], false);
        $current = $base['rows'][0]['value'] ?? null;
        if ($current === null) {
            $this->invalid('A scenario needs an unsuppressed baseline.');
        }
        if ($d['leavers'] > $current + $d['hires']) {
            $this->invalid('Assumed leavers exceed the available workforce.');
        }
        $result = ['baseline' => $current, 'projected' => $current + $d['hires'] - $d['leavers'], 'formula' => 'baseline + assumed hires - assumed leavers', 'assumptions' => $d,
            'limitations' => 'A user-defined scenario, not a forecast or an employee risk score. Assumes one employment per planned hire/leaver.'];
        $s = Scenario::create(['actor_id' => $u->id, 'assumptions' => $d, 'result' => $result]);
        AccessLog::create(['actor_id' => $u->id, 'action' => 'scenario', 'spec' => $d]);
        Audit::record('analytics.scenario', 'analytics', 'scenario:'.$s->uuid);

        return ['uuid' => $s->uuid] + $result;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['report' => $message]);
    }
}
