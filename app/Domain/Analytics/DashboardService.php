<?php

namespace App\Domain\Analytics;

use App\Domain\Access\DataScope;
use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Models\Report;
use App\Domain\Audit\Audit;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Role boards built from saved widgets. A board label never grants data: every widget is computed with the VIEWER's own
 * permission and data scope, and drill-downs point at the existing permissioned list endpoints.
 */
final class DashboardService
{
    public const AUDIENCES = ['employee' => 0, 'manager' => 1, 'hr' => 2, 'executive' => 3];

    public function __construct(private AnalyticsService $analytics) {}

    /** Reach tier of the viewer: employee < manager < hr < executive. */
    public function tier(User $u): int
    {
        $scopes = app(DataScope::class)->scopesFor($u, 'people.view');
        $tenantWide = in_array('tenant', $scopes, true);
        if ($tenantWide && $u->allows('payroll.reports.view')) {
            return 3;
        }
        if ($tenantWide) {
            return 2;
        }

        return collect($scopes)->contains(fn ($s) => $s !== 'self') ? 1 : 0;
    }

    public function visible(User $u)
    {
        $tier = $this->tier($u);

        return Dashboard::query()->latest('id')->get()->filter(fn ($b) => self::AUDIENCES[$b->audience] <= $tier)->values();
    }

    public function validate(User $u, array $input): array
    {
        $d = validator($input, ['name' => 'required|string|max:150', 'audience' => 'required|in:'.implode(',', array_keys(self::AUDIENCES)),
            'widgets' => 'required|array|min:1|max:12', 'widgets.*.title' => 'required|string|max:100', 'widgets.*.report_uuid' => 'nullable|uuid',
            'widgets.*.metric' => 'required_without:widgets.*.report_uuid|string', 'widgets.*.dimension' => 'nullable|string', 'widgets.*.days' => 'nullable|integer|between:1,366'])->validate();
        $widgets = [];
        foreach ($d['widgets'] as $i => $w) {
            if (! empty($w['report_uuid'])) {
                $report = Report::query()->where('uuid', $w['report_uuid'])->where('owner_id', $u->id)->first();
                if (! $report) {
                    $this->invalid("Widget ".($i + 1).": the saved report was not found.");
                }
                $w['metric'] = $report->spec['metric'];
                $w['dimension'] = $report->spec['dimension'];
            }
            $w['dimension'] ??= 'total';
            $spec = $this->analytics->validateSpec(['metric' => $w['metric'], 'dimension' => $w['dimension'], 'from' => CarbonImmutable::parse(TenantClock::today())->subDays(30)->toDateString(), 'to' => TenantClock::today()]);
            $metric = AnalyticsService::CATALOG[$spec['metric']];
            if (! $u->allows($metric['permission'])) {
                $this->invalid('Widget '.($i + 1).': you do not hold the permission for this metric.');
            }
            if ($d['audience'] === 'employee' && ! in_array($spec['metric'], MetricDocs::EMPLOYEE_SAFE, true)) {
                $this->invalid('Widget '.($i + 1).': this metric is not employee-safe.');
            }
            if ($d['audience'] === 'manager' && in_array($spec['metric'], MetricDocs::HR_ONLY, true)) {
                $this->invalid('Widget '.($i + 1).': this metric is limited to HR and executive boards.');
            }
            if ($d['audience'] === 'employee' && $spec['dimension'] !== 'total') {
                $this->invalid('Employee-safe boards show totals only.');
            }
            $widgets[] = ['id' => $w['id'] ?? 'w'.($i + 1), 'title' => $w['title'], 'metric' => $spec['metric'], 'dimension' => $spec['dimension'], 'days' => (int) ($w['days'] ?? 30)];
        }

        return ['name' => $d['name'], 'audience' => $d['audience'], 'widgets' => $widgets];
    }

    public function render(User $u, Dashboard $board, ?string $asOf = null): array
    {
        abort_unless(self::AUDIENCES[$board->audience] <= $this->tier($u), 404);
        $today = TenantClock::today();
        $asOf ??= $today;
        validator(['as_of' => $asOf], ['as_of' => 'date_format:Y-m-d|before_or_equal:'.$today])->validate();
        $out = [];
        foreach ($board->widgets as $w) {
            $spec = ['metric' => $w['metric'], 'dimension' => $w['dimension'], 'to' => $asOf, 'from' => CarbonImmutable::parse($asOf)->subDays(max(1, (int) $w['days']) - 1)->toDateString()];
            $item = ['id' => $w['id'], 'title' => $w['title'], 'spec' => $spec];
            try {
                $result = $this->analytics->query($u, $spec, false);
                $item += ['status' => isset($result['quality']['blocked']) ? 'blocked' : 'ok', 'result' => $result, 'drilldown' => $this->drilldown($u, $spec)];
            } catch (HttpException) {
                $item += ['status' => 'restricted', 'message' => 'Your role cannot view this widget.'];
            } catch (ValidationException $e) {
                $item += ['status' => 'unavailable', 'message' => collect($e->errors())->flatten()->first()];
            }
            $out[] = $item;
        }
        Audit::record('analytics.dashboard.viewed', 'analytics', 'dashboard:'.$board->uuid, ['as_of' => $asOf]);

        return ['uuid' => $board->uuid, 'name' => $board->name, 'audience' => $board->audience, 'as_of' => $asOf, 'widgets' => $out];
    }

    /** Where the underlying list lives. Always an existing, separately permissioned endpoint; never a bulk record dump from analytics. */
    public function drilldown(User $u, array $spec): ?array
    {
        $metric = $spec['metric'];
        $people = ['headcount', 'tenure', 'retention_cohort', 'attrition', 'hires', 'leavers'];
        if (in_array($metric, $people, true)) {
            $filter = in_array($metric, ['headcount', 'tenure'], true) ? ['status' => ['active']] : [];
            if ($metric === 'hires' || $metric === 'retention_cohort') {
                $filter += ['joined_from' => $spec['from']];
            }
            $dimensionFilter = ['department' => 'department_id', 'location' => 'location_id', 'legal_entity' => 'legal_entity_id'][$spec['dimension']] ?? null;

            return ['permission' => 'people.view', 'allowed' => $u->allows('people.manage') || $u->allows('people.view'), 'api' => '/v1/employees', 'ui' => '/people', 'query' => $filter,
                'row_filter' => $dimensionFilter, 'note' => 'Lists current employments in your scope; historical membership as of '.$spec['to'].' may differ.'];
        }
        $ui = ['overtime' => ['/attendance', 'attendance.reports.view'], 'absence' => ['/attendance', 'attendance.reports.view'], 'learning_completions' => ['/learning', 'learning.reports.view'],
            'training_compliance' => ['/learning', 'learning.reports.view'], 'leave_utilization' => ['/leave', 'leave.reports.view'], 'leave_liability' => ['/leave', 'leave.reports.view'],
            'recruitment_pipeline' => ['/recruitment', 'recruitment.reports.view'], 'time_to_hire' => ['/recruitment', 'recruitment.reports.view'], 'offer_accept_rate' => ['/recruitment', 'recruitment.reports.view'],
            'performance_status' => ['/performance', 'performance.reports.view'], 'rating_distribution' => ['/performance', 'performance.reports.view'], 'ticket_sla' => ['/experience', 'experience.analytics.view'],
            'payroll_totals' => ['/payroll', 'payroll.reports.view'], 'payroll_cost_trend' => ['/payroll', 'payroll.reports.view']][$metric] ?? null;

        return $ui ? ['permission' => $ui[1], 'allowed' => $u->allows($ui[1]), 'api' => null, 'ui' => $ui[0], 'query' => ['from' => $spec['from'], 'to' => $spec['to']], 'row_filter' => null,
            'note' => 'Opens the module report; the module applies its own permission and data scope.'] : null;
    }

    private function invalid(string $m): never
    {
        throw ValidationException::withMessages(['widgets' => $m]);
    }
}
