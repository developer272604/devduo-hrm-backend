<?php

namespace App\Domain\Payroll\Operations;

use App\Domain\Insights\InsightProvider;
use App\Domain\Payroll\Readiness\ReadinessEngine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Payroll contribution to the Action Center (spec 76). Deterministic, scoped to the viewer, every insight actionable and carrying
 * what (title), why (description), recommendation and the affected sample. Derived from the readiness checks plus failed calculations,
 * payment failures and variance alerts.
 */
final class PayrollInsightProvider implements InsightProvider
{
    private const SEVERITY = ['information' => 'info', 'warning' => 'warning', 'blocking' => 'action_required', 'critical' => 'critical'];

    public function __construct(private ReadinessEngine $readiness) {}

    public function key(): string
    {
        return 'payroll';
    }

    public function insights(User $viewer): array
    {
        if (! $viewer->tenant_id || ! PayrollOps::any($viewer, PayrollOps::VIEW) || ! PayrollOps::moduleEnabled($viewer, 'payroll')) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($this->readiness->groups($viewer) as $g) {
            foreach ($g['checks'] as $c) {
                if ($c['state'] !== 'open' || $c['severity'] === 'information') {
                    continue;
                }
                $key = 'payroll.'.$c['key'];
                $seen[$key] = ($seen[$key] ?? 0) + 1;
                $out[] = ['key' => $key.'.'.$g['group']['code'], 'domain' => 'payroll', 'severity' => self::SEVERITY[$c['severity']], 'title' => $c['count'].' '.$c['unit'].': '.$c['label'].' ('.$g['group']['name'].', '.($g['period']['label'] ?? 'no period').')',
                    'count' => $c['count'], 'description' => $c['why'], 'recommendation' => $c['action']['label'], 'actions' => [$c['action'] + ['requires_confirmation' => false]], 'sample' => array_map(fn ($s) => ['employee_uuid' => $s['employee_uuid'], 'name' => $s['name']], array_slice($c['sample'], 0, 5))];
            }
        }
        $tenant = PayrollOps::tenantId();
        $nav = fn (string $label, string $href) => ['label' => $label, 'kind' => 'navigate', 'href' => $href, 'requires_confirmation' => false];
        $add = function (string $name, string $severity, int $count, string $title, string $why, array $action) use (&$out) {
            if ($count > 0) {
                $out[] = ['key' => 'payroll.'.$name, 'domain' => 'payroll', 'severity' => $severity, 'title' => $title, 'count' => $count, 'description' => $why, 'recommendation' => $action['label'], 'actions' => [$action], 'sample' => []];
            }
        };
        $failed = (int) DB::table('payroll_runs')->where('tenant_id', $tenant)->whereNotIn('status', ['closed', 'cancelled'])->sum('employees_failed');
        $add('failed_calculation', 'action_required', $failed, $failed.' employee calculations failed', 'A failed employee is excluded from the run until fixed.', $nav('Open the run', '/payroll/runs'));
        $payFail = DB::table('payment_instructions')->where('tenant_id', $tenant)->where('status', 'failed')->count();
        $add('payment_failure', 'critical', $payFail, $payFail.' payment instructions failed', 'The bank rejected or could not process these payments.', $nav('Open payments', '/payroll/payments'));
        $variance = DB::table('payroll_validation_issues')->where('tenant_id', $tenant)->where('code', 'like', 'variance%')->whereIn('status', ['open', 'acknowledged'])->count();
        $add('large_variance', 'warning', $variance, $variance.' large pay variances', 'Net pay moved more than the configured threshold versus the previous run.', $nav('Review variances', '/payroll/validations'));

        return $out;
    }
}
