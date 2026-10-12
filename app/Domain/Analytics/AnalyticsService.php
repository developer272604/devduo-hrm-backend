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
use App\Domain\Leave\Support\Units;
use App\Domain\Payroll\Support\Decimal;
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
        'retention_cohort' => ['label' => 'Retention of hire cohort', 'permission' => 'people.view', 'module' => 'people', 'unit' => 'percent', 'formula' => '100 × hire-cohort employments still working at period end / employments that joined in the period.', 'source' => 'employees + employee_status_history'],
        'overtime' => ['label' => 'Approved overtime', 'permission' => 'attendance.reports.view', 'module' => 'attendance', 'unit' => 'minutes', 'formula' => 'Sum approved overtime minutes in confirmed, approved or locked attendance days. Unfinalized days excluded.', 'source' => 'attendance_days.approved_overtime_minutes'],
        'absence' => ['label' => 'Absence rate', 'permission' => 'attendance.reports.view', 'module' => 'attendance', 'unit' => 'percent', 'formula' => '100 × absent days / scheduled days, among confirmed approved/locked attendance rows with required_minutes > 0.', 'source' => 'attendance_days'],
        'learning_completions' => ['label' => 'Learning completions', 'permission' => 'learning.reports.view', 'module' => 'learning', 'unit' => 'completions', 'formula' => 'Completed enrolments with completed_at in the period, interpreted in the workspace timezone.', 'source' => 'learning_enrolments.completed_at'],
        'leave_utilization' => ['label' => 'Consumed leave units', 'permission' => 'leave.reports.view', 'module' => 'leave', 'unit' => 'leave units', 'formula' => 'Sum posted consumption ledger units by effective date. Pending reservations are excluded.', 'source' => 'leave_ledger_entries.entry_type + units + effective_date'],
        'training_compliance' => ['label' => 'Training compliance', 'permission' => 'learning.reports.view', 'module' => 'learning', 'unit' => 'percent', 'formula' => '100 × completed / (completed + open) mandatory assignments due in the period; waived excluded.', 'source' => 'learning_assignments.status + due_on'],
        'leave_liability' => ['label' => 'Leave liability (days)', 'permission' => 'leave.reports.view', 'module' => 'leave', 'unit' => 'days', 'formula' => 'Σ over employments and paid leave types of max(0, ledger balance at period end), reservations excluded; decimal-safe.', 'source' => 'leave_ledger_entries + leave_types'],
        'time_to_hire' => ['label' => 'Time to hire', 'permission' => 'recruitment.reports.view', 'module' => 'recruitment', 'unit' => 'days', 'formula' => 'Mean days from application creation to accepted offer response, offers accepted in the period.', 'source' => 'recruitment_offers + recruitment_applications'],
        'offer_accept_rate' => ['label' => 'Offer acceptance rate', 'permission' => 'recruitment.reports.view', 'module' => 'recruitment', 'unit' => 'percent', 'formula' => '100 × accepted / (accepted + declined) offers answered in the period.', 'source' => 'recruitment_offers'],
        'rating_distribution' => ['label' => 'Performance rating distribution', 'permission' => 'performance.reports.view', 'module' => 'performance', 'unit' => 'reviews', 'formula' => 'Finalized reviews in the period counted per final rating label.', 'source' => 'performance_reviews.final_rating'],
        'payroll_cost_trend' => ['label' => 'Payroll cost trend', 'permission' => 'payroll.reports.view', 'module' => 'payroll', 'unit' => 'currency amount', 'formula' => 'Gross and net by payroll period end date and currency (optionally by department as of the report end); decimal strings.', 'source' => 'payroll_calculation_results + payroll_runs + payroll_periods'],
        'recruitment_pipeline' => ['label' => 'Recruitment pipeline', 'permission' => 'recruitment.reports.view', 'module' => 'recruitment', 'unit' => 'applications', 'formula' => 'Current application counts by stage for applications whose stage_entered_at falls in the period. External candidate records are never returned.', 'source' => 'recruitment_applications.stage + stage_entered_at'],
        'performance_status' => ['label' => 'Finalized performance reviews', 'permission' => 'performance.reports.view', 'module' => 'performance', 'unit' => 'reviews', 'formula' => 'Reviews finalized in the selected period, restricted to currently authorized employees.', 'source' => 'performance_reviews.status + finalized_at + employee_id'],
        'ticket_sla' => ['label' => 'Helpdesk SLA attainment', 'permission' => 'experience.analytics.view', 'module' => 'experience', 'unit' => 'percent', 'formula' => 'Resolved tickets with a first response at or before sla_due_at divided by resolved tickets with an SLA and a first response.', 'source' => 'experience_tickets.sla_due_at + first_response_at + resolved_at'],
        'payroll_totals' => ['label' => 'Published payroll totals', 'permission' => 'payroll.reports.view', 'module' => 'payroll', 'unit' => 'currency amount', 'formula' => 'Gross and net totals by currency for locked or closed payroll results in the period and within current employee scope.', 'source' => 'payroll_runs + payroll_periods + payroll_calculation_results'],
    ];

    public function catalog(User $u): array
    {
        return collect(self::CATALOG)->filter(fn ($m) => $u->allows($m['permission']) && in_array($m['module'], $u->tenant->settings['modules'] ?? [], true))
            ->map(fn ($m, $key) => ['key' => $key, 'version' => 1, 'owner' => $m['module'], 'cadence' => 'on demand', 'minimum_cohort' => self::COHORT,
                'dimensions' => $this->dimensionsFor($key)] + $m + ['explain' => MetricDocs::explain($key)])->values()->all();
    }

    public const ORG_DIMENSION_METRICS = ['headcount', 'tenure', 'retention_cohort', 'payroll_cost_trend'];

    /** Metrics whose rows are built by the metric itself rather than as one value row per group. */
    private const CUSTOM_ROWS = ['recruitment_pipeline', 'payroll_totals', 'payroll_cost_trend', 'rating_distribution'];

    public function dimensionsFor(string $key): array
    {
        $dims = in_array($key, self::ORG_DIMENSION_METRICS, true) ? ['total', 'department', 'location', 'legal_entity'] : ['total'];
        if ($key === 'headcount' && in_array('gender', (array) TenantClock::setting('analytics.diversity_dimensions', []), true)) {
            $dims[] = 'gender';
        }

        return $dims;
    }

    public function explain(User $u, string $key): array
    {
        abort_unless(isset(self::CATALOG[$key]), 404);
        abort_unless($u->allows('analytics.view') && $u->allows(self::CATALOG[$key]['permission']) && in_array(self::CATALOG[$key]['module'], $u->tenant->settings['modules'] ?? [], true), 403);

        return MetricDocs::explain($key) + ['version' => 1, 'minimum_cohort' => self::COHORT, 'dimensions' => $this->dimensionsFor($key)];
    }

    public function validateSpec(array $input): array
    {
        $d = validator($input, ['metric' => 'required|in:'.implode(',', array_keys(self::CATALOG)),
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'dimension' => 'required|in:total,department,location,legal_entity,gender'])->validate();
        if ($d['to'] > TenantClock::today() || CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) > 366) {
            $this->invalid('Choose a historical period of at most 367 inclusive days.');
        }
        if (! in_array($d['metric'], self::ORG_DIMENSION_METRICS, true) && $d['dimension'] !== 'total') {
            $this->invalid('This metric supports totals only.');
        }
        if ($d['dimension'] === 'gender' && ($d['metric'] !== 'headcount' || ! in_array('gender', $this->dimensionsFor('headcount'), true))) {
            $this->invalid('Diversity cuts are disabled for this workspace. An administrator must enable them explicitly in analytics settings.');
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
            $employees = app(DataScope::class)->constrain(Employee::query(), $u, $metric['permission'])->limit(10001)->get(['id', 'person_id', 'joined_on', 'separated_on']);
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
            $genders = $d['dimension'] === 'gender' ? DB::table('people')->where('tenant_id', $u->tenant_id)->whereIn('id', $employees->pluck('person_id'))->pluck('gender', 'id') : collect();
            $groups = match ($d['dimension']) {
                'total' => collect(['total' => $employees]),
                'gender' => $employees->groupBy(fn ($e) => ($genders->get($e->person_id) ?: 'Undisclosed')),
                default => $employees->groupBy(fn ($e) => $assignmentByEmployee->get($e->id)?->{$d['dimension'].'_id'} ?? 'unassigned'),
            };
            if ($groups->count() > 100) {
                $this->invalid('The report exceeds the 100-group limit.');
            }
            $labels = [];
            if (! in_array($d['dimension'], ['total', 'gender'], true)) {
                $table = ['department' => 'departments', 'location' => 'locations', 'legal_entity' => 'legal_entities'][$d['dimension']];
                $labels = OrgNameHistory::labelsAsOf($u->tenant_id, $d['dimension'], DB::table($table)->where('tenant_id', $u->tenant_id)->pluck('name', 'id')->all(), $d['to']);
            } elseif ($d['dimension'] === 'gender') {
                $labels = $groups->keys()->mapWithKeys(fn ($k) => [$k => $k])->all();
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
                    case 'retention_cohort':
                        $cohortMembers = $members->filter(fn ($e) => $e->joined_on->toDateString() >= $d['from'] && $e->joined_on->toDateString() <= $d['to']);
                        $denominator = $cohortMembers->count();
                        $numerator = $cohortMembers->filter(fn ($e) => in_array($atEnd->get($e->id), Employee::WORKING, true))->count();
                        $cohort = $denominator;
                        $smallEvent = ($denominator - $numerator) > 0 && ($denominator - $numerator) < self::COHORT;
                        break;
                    case 'training_compliance':
                        $q = DB::table('learning_assignments')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $members->pluck('id'))
                            ->whereIn('status', ['assigned', 'completed'])->whereBetween('due_on', [$d['from'], $d['to']]);
                        $denominator = (clone $q)->count();
                        $numerator = (clone $q)->where('status', 'completed')->count();
                        $cohort = (clone $q)->distinct()->count('employee_id');
                        $open = (clone $q)->where('status', 'assigned')->distinct()->count('employee_id');
                        $smallEvent = $open > 0 && $open < self::COHORT;
                        break;
                    case 'leave_liability':
                        $entries = DB::table('leave_ledger_entries as l')->join('leave_types as t', 't.id', '=', 'l.leave_type_id')->where('l.tenant_id', $u->tenant_id)
                            ->whereIn('l.employee_id', $members->pluck('id'))->where('t.category', 'paid')->where('t.tracks_balance', true)->whereIn('t.unit', ['day', 'half_day', 'quarter_day'])
                            ->whereNotIn('l.entry_type', ['reservation', 'reservation_release'])->where('l.effective_date', '<=', $d['to'])->get(['l.employee_id', 'l.leave_type_id', 'l.units']);
                        $balances = [];
                        foreach ($entries as $entry) {
                            $k = $entry->employee_id.':'.$entry->leave_type_id;
                            $balances[$k] = Units::add($balances[$k] ?? '0', $entry->units);
                        }
                        $total = '0.0000';
                        $holders = [];
                        foreach ($balances as $k => $balance) {
                            if (Units::cmp($balance, '0') > 0) {
                                $total = Units::add($total, $balance);
                                $holders[explode(':', $k)[0]] = true;
                            }
                        }
                        $numerator = $total;
                        $cohort = count($holders);
                        break;
                    case 'time_to_hire':
                    case 'offer_accept_rate':
                        $timezone = $u->tenant->settings['timezone'] ?? 'UTC';
                        $offers = DB::table('recruitment_offers as o')->join('recruitment_applications as a', 'a.id', '=', 'o.application_id')->where('o.tenant_id', $u->tenant_id)
                            ->whereIn('o.status', ['accepted', 'declined'])
                            ->where('o.responded_at', '>=', CarbonImmutable::parse($d['from'], $timezone)->setTimezone(config('app.timezone')))
                            ->where('o.responded_at', '<', CarbonImmutable::parse($d['to'], $timezone)->addDay()->setTimezone(config('app.timezone')))->get(['o.status', 'o.responded_at', 'a.created_at']);
                        $accepted = $offers->where('status', 'accepted');
                        $declined = $offers->where('status', 'declined')->count();
                        if ($d['metric'] === 'time_to_hire') {
                            $numerator = $accepted->sum(fn ($o) => (int) CarbonImmutable::parse($o->created_at)->diffInDays(CarbonImmutable::parse($o->responded_at)));
                            $denominator = $accepted->count();
                            $cohort = $accepted->count();
                        } else {
                            $numerator = $accepted->count();
                            $denominator = $offers->count();
                            $cohort = $offers->count();
                            $smallEvent = ($numerator > 0 && $numerator < self::COHORT) || ($declined > 0 && $declined < self::COHORT);
                        }
                        break;
                    case 'rating_distribution':
                        $timezone = $u->tenant->settings['timezone'] ?? 'UTC';
                        $q = DB::table('performance_reviews')->where('tenant_id', $u->tenant_id)->whereIn('employee_id', $members->pluck('id'))->whereIn('status', ['finalized', 'acknowledged'])->whereNotNull('final_rating')
                            ->where('finalized_at', '>=', CarbonImmutable::parse($d['from'], $timezone)->setTimezone(config('app.timezone')))
                            ->where('finalized_at', '<', CarbonImmutable::parse($d['to'], $timezone)->addDay()->setTimezone(config('app.timezone')));
                        $counts = (clone $q)->select('final_rating', DB::raw('COUNT(*) as total'))->groupBy('final_rating')->orderBy('final_rating')->get();
                        $cohort = (clone $q)->distinct()->count('employee_id');
                        $small = $cohort < self::COHORT || $counts->contains(fn ($row) => $row->total < self::COHORT);
                        $prefix = $d['dimension'] === 'total' ? '' : ($labels[$group] ?? $group).' · ';
                        foreach ($counts as $row) {
                            $rows[] = ['group' => $prefix.(string) $row->final_rating, 'value' => $small ? null : (int) $row->total, 'numerator' => $small ? null : (int) $row->total, 'denominator' => null, 'suppressed' => $small];
                        }
                        $suppressed = $suppressed || $small;
                        break;
                    case 'payroll_cost_trend':
                        $results = DB::table('payroll_calculation_results as r')->join('payroll_runs as pr', 'pr.id', '=', 'r.run_id')->join('payroll_periods as pp', 'pp.id', '=', 'pr.period_id')
                            ->where('r.tenant_id', $u->tenant_id)->whereIn('r.employee_id', $members->pluck('id'))->where('r.is_current', true)->whereIn('r.status', ['ok', 'warning'])->whereIn('pr.status', ['locked', 'closed'])
                            ->whereBetween('pp.period_end', [$d['from'], $d['to']])->orderBy('pp.period_end')->get(['pp.period_end as end_date', 'r.currency', 'r.employee_id', 'r.gross', 'r.net']);
                        $prefix = $d['dimension'] === 'total' ? '' : ($labels[$group] ?? $group).' · ';
                        $previous = [];
                        foreach ($results->groupBy(fn ($r) => $r->end_date.'|'.$r->currency) as $key => $cell) {
                            [$end, $currency] = explode('|', $key);
                            $gross = Decimal::of('0');
                            $net = Decimal::of('0');
                            foreach ($cell as $r) {
                                $gross = Decimal::add($gross, Decimal::of((string) $r->gross));
                                $net = Decimal::add($net, Decimal::of((string) $r->net));
                            }
                            $hide = $cell->pluck('employee_id')->unique()->count() < self::COHORT;
                            $before = $previous[$currency] ?? null;
                            $pct = fn ($now, $was) => $was !== null && ! $was->isZero() ? round(((float) (string) $now - (float) (string) $was) / abs((float) (string) $was) * 100, 2) : null;
                            $rows[] = ['group' => $prefix.$end.' · '.$currency, 'value' => $hide ? null : ['gross' => (string) $gross, 'net' => (string) $net,
                                'gross_change_percent' => $hide ? null : $pct($gross, $before['gross'] ?? null), 'net_change_percent' => $hide ? null : $pct($net, $before['net'] ?? null)],
                                'numerator' => null, 'denominator' => null, 'suppressed' => $hide];
                            $previous[$currency] = $hide ? null : ['gross' => $gross, 'net' => $net];
                            $suppressed = $suppressed || $hide;
                        }
                        $cohort = $results->pluck('employee_id')->unique()->count();
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
                        $current = (clone $base)->whereBetween('pp.period_end', [$d['from'], $d['to']]);
                        $days = CarbonImmutable::parse($d['from'])->diffInDays(CarbonImmutable::parse($d['to'])) + 1;
                        $previousFrom = CarbonImmutable::parse($d['from'])->subDays($days)->toDateString();
                        $previousTo = CarbonImmutable::parse($d['from'])->subDay()->toDateString();
                        $previous = (clone $base)->whereBetween('pp.period_end', [$previousFrom, $previousTo]);
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
                $value = $denominator === null ? $numerator : ($denominator > 0 ? round($numerator / $denominator * (in_array($d['metric'], ['attrition', 'absence', 'retention_cohort', 'training_compliance', 'offer_accept_rate', 'ticket_sla'], true) ? 100 : 1), 2) : null);
                if (! in_array($d['metric'], self::CUSTOM_ROWS, true)) {
                    $rows[] = ['group' => $group === 'total' ? 'Total' : ($labels[$group] ?? 'Unassigned'), 'value' => $hide ? null : $value,
                        'numerator' => $hide ? null : $numerator, 'denominator' => $hide ? null : $denominator, 'suppressed' => $hide,
                        'key' => $group === 'total' || $group === 'unassigned' ? null : (string) $group];
                }
            }
            // Suppress the whole breakdown if any cell is small; no remainder/total can reveal it.
            if ($suppressed && $d['dimension'] !== 'total') {
                $rows = [['group' => 'Breakdown withheld', 'value' => null, 'numerator' => null, 'denominator' => null, 'suppressed' => true]];
            }
            if (! $rows) {
                $rows = [['group' => 'Total', 'value' => null, 'numerator' => null, 'denominator' => null, 'suppressed' => true]];
            }
            $explain = MetricDocs::explain($d['metric']) + ['as_of' => $d['to'], 'period' => ['from' => $d['from'], 'to' => $d['to']],
                'filters' => ['dimension' => $d['dimension'], 'scope' => 'Current authorized employee scope and source permission of the viewer.'],
                'suppression_note' => $suppressed ? 'One or more values were withheld because fewer than '.self::COHORT.' contributing records would be identifiable. Suppressed is not zero.' : 'No values were withheld for this result.',
                'organization_labels' => $d['dimension'] === 'total' || $d['dimension'] === 'gender' ? null : 'Names as recorded on '.$d['to'].' from observed name history; membership from assignments effective on that date.'];
            $result = ['metric' => $d['metric'], 'version' => 1, 'explain' => $explain, 'label' => $metric['label'], 'unit' => $metric['unit'], 'definition' => $metric['formula'], 'lineage' => $metric['source'],
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
            if ($missingHistory && in_array($d['metric'], ['headcount', 'tenure', 'attrition', 'retention_cohort'], true)) {
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
