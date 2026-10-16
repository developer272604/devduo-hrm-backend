<?php

namespace App\Domain\Intelligence;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Intelligence\Models\ScenarioRun;
use App\Domain\Intelligence\Planning\WorkforcePlanner;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Simulation\SimulationService as LeaveSimulation;
use App\Domain\People\Models\Employee;
use App\Domain\Payroll\Simulation\SimulationService as PayrollSimulation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only what-if orchestration. Each scenario runs the owning module's existing simulator for the viewer (that module applies its own
 * permission and data scope) inside a database transaction that is ALWAYS rolled back, so nothing a simulator persists (its own run
 * log included) can reach those domains. Only the Intelligence scenario record is stored, after the rollback.
 */
final class ScenarioService
{
    /** kind => [required permission, handler] */
    public const KINDS = [
        'leave_policy_sandbox' => ['leave.simulate', 'leaveSandbox'],
        'leave_forecast' => ['leave.simulate', 'leaveForecast'],
        'payroll_simulation' => ['payroll.simulate', 'payroll'],
        'headcount_plan' => ['intelligence.view', 'headcount'],
        'workforce_plan' => ['intelligence.scenarios.run', 'workforcePlan'],
    ];

    /** Extra scenario sources can be registered (kind => [permission, callable(User,array):array]); used by other modules and tests. */
    private array $extra = [];

    public function register(string $kind, string $permission, callable $handler): void
    {
        $this->extra[$kind] = [$permission, $handler];
    }

    public function kinds(User $viewer): array
    {
        $all = array_merge(array_map(fn ($k) => $k[0], self::KINDS), array_map(fn ($k) => $k[0], $this->extra));

        return collect($all)->map(fn ($perm, $kind) => ['kind' => $kind, 'permission' => $perm, 'available' => $viewer->allows($perm)])->values()->all();
    }

    public function run(User $viewer, string $kind, array $params, ?string $title = null): array
    {
        $def = $this->extra[$kind] ?? (self::KINDS[$kind] ?? null);
        abort_unless($def, 422, 'Unknown scenario kind.');
        abort_unless($viewer->allows($def[0]), 403, 'You do not have the permission this scenario needs.');
        DB::beginTransaction();
        try {
            $result = is_callable($def[1]) ? ($def[1])($viewer, $params) : $this->{$def[1]}($viewer, $params);
        } finally {
            DB::rollBack(); // guarantee: scenarios never write to the source domains
        }
        $result = ['kind' => $kind, 'read_only' => true, 'writes_rolled_back' => true, 'deterministic' => true, 'result' => $result];
        $run = ScenarioRun::create(['kind' => $kind, 'title' => $title, 'params' => $params, 'result' => $result, 'created_by' => $viewer->id]);
        Audit::record('intelligence.scenario.run', 'intelligence', 'scenario:'.$run->uuid, ['kind' => $kind]);

        return ['uuid' => $run->uuid, 'title' => $title, 'created_at' => $run->created_at?->toIso8601String()] + $result;
    }

    public function history(User $viewer): array
    {
        return ScenarioRun::where('created_by', $viewer->id)->orderByDesc('id')->limit(50)->get()
            ->map(fn ($r) => ['uuid' => $r->uuid, 'kind' => $r->kind, 'title' => $r->title, 'created_at' => $r->created_at?->toIso8601String()])->all();
    }

    public function show(User $viewer, string $uuid): array
    {
        $r = ScenarioRun::where('uuid', $uuid)->where('created_by', $viewer->id)->firstOrFail();

        return ['uuid' => $r->uuid, 'title' => $r->title, 'params' => $r->params, 'created_at' => $r->created_at?->toIso8601String()] + $r->result;
    }

    private function leaveSandbox(User $viewer, array $p): array
    {
        $uuids = array_values((array) ($p['employee_uuids'] ?? []));
        $ids = LeaveOps::employees($viewer, 'leave.simulate')->whereIn('e.uuid', $uuids)->pluck('e.id')->map(fn ($v) => (int) $v)->all();
        abort_if($ids === [], 404, 'None of the selected employees are in your scope.');
        foreach (['leave_type_id', 'period_start', 'period_end'] as $k) {
            if (empty($p[$k])) {
                throw ValidationException::withMessages(['params.'.$k => 'Required.']);
            }
        }

        return app(LeaveSimulation::class)->sandbox($viewer, array_intersect_key($p, array_flip(['leave_type_id', 'rules', 'policy_version_id', 'period_start', 'period_end'])), $ids);
    }

    private function leaveForecast(User $viewer, array $p): array
    {
        $uuid = (string) ($p['employee_uuid'] ?? '');
        $employee = LeaveOps::visibleEmployee($viewer, $uuid, 'leave.simulate', null);

        return app(LeaveSimulation::class)->forecast($employee, isset($p['leave_type_id']) ? (int) $p['leave_type_id'] : null, (int) ($p['months'] ?? 6), $viewer);
    }

    private function payroll(User $viewer, array $p): array
    {
        $kind = (string) ($p['simulation_kind'] ?? '');

        return app(PayrollSimulation::class)->run($viewer, $kind, (array) ($p['params'] ?? []), array_values((array) ($p['employee_uuids'] ?? [])), null);
    }

    /** Headcount plan: current working headcount in the viewer's scope projected with planned monthly hires and exits (simple, transparent arithmetic). */
    private function headcount(User $viewer, array $p): array
    {
        $months = max(1, min(36, (int) ($p['months'] ?? 6)));
        $hires = max(0, (int) ($p['hires_per_month'] ?? 0));
        $exits = max(0, (int) ($p['exits_per_month'] ?? 0));
        $current = $this->scopedHeadcount($viewer);
        $series = [];
        $level = $current;
        for ($m = 1; $m <= $months; $m++) {
            $level = max(0, $level + $hires - $exits);
            $series[] = ['month' => $m, 'headcount' => $level];
        }

        return ['current_headcount' => $current, 'assumptions' => ['hires_per_month' => $hires, 'exits_per_month' => $exits, 'months' => $months], 'series' => $series, 'projected_headcount' => $level,
            'method' => 'current + months x (hires - exits), floored at zero. Assumptions are yours; no forecast model is used.'];
    }

    private function scopedHeadcount(User $viewer): int
    {
        $q = DB::table('employees')->where('employees.tenant_id', $viewer->tenant_id)->whereNull('employees.deleted_at')->whereIn('employees.status', Employee::WORKING);

        return app(DataScope::class)->constrain($q, $viewer, 'intelligence.view')->count();
    }

    /** Multi-alternative workforce plan with an attrition range, hiring lead time, ramp-up and optional budget. All inputs are the user's assumptions. */
    private function workforcePlan(User $viewer, array $p): array
    {
        return WorkforcePlanner::plan($p, $this->scopedHeadcount($viewer));
    }
}
