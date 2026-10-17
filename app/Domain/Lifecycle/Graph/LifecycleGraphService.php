<?php

namespace App\Domain\Lifecycle\Graph;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Lifecycle\Contracts\ClearanceRegistry;
use App\Domain\Lifecycle\Contracts\NullClearanceProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Employee Lifecycle Dependency Graph. One read-only view of everything that has to be settled before a person's exit can close, drawn as
 * nodes with the dependencies between them: what is clear, what is blocked and by whom, what has not been checked here, and which blocked item
 * to work on first. It reuses the departments already registered for offboarding clearance, and adds checks for pending leave, running workflows,
 * open lifecycle tasks and uncovered know-how. It informs the people doing the exit; it does not change what offboarding enforces.
 */
final class LifecycleGraphService
{
    private const LABELS = ['assets' => 'Assets returned', 'expenses' => 'Expense claims settled', 'loans' => 'Loans and advances', 'leave' => 'Leave decisions', 'payroll' => 'Payroll inputs',
        'workflows' => 'Running workflows', 'handover' => 'Knowledge handover', 'tasks' => 'Open exit tasks', 'settlement' => 'Final settlement', 'access' => 'Access removal'];

    /** Derived nodes and what each one waits for. */
    private const DEPENDS = ['settlement' => ['assets', 'expenses', 'loans', 'leave', 'payroll', 'workflows'], 'access' => ['handover', 'tasks', 'settlement']];

    public function __construct(private DataScope $scope, private ClearanceRegistry $registry) {}

    public function forEmployee(User $u, string $employeeUuid): array
    {
        $q = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $u->tenant_id)->where('employees.uuid', $employeeUuid)->whereNull('employees.deleted_at');
        $emp = $this->scope->constrain($q, $u, 'lifecycle.view')->first(['employees.id', 'employees.uuid', 'employees.status', 'people.display_name as name']);
        abort_unless($emp, 404);

        $nodes = [];
        foreach ($this->registry->providers() as $key => $provider) {
            $checked = ! $provider instanceof NullClearanceProvider;
            $b = $checked ? $provider->blockers($emp->id) : [];
            $nodes[$key] = $this->node($key, $checked, $b);
        }
        // Departments that always appear, even when no module has registered a provider for them yet.
        foreach (['assets', 'expenses', 'loans', 'payroll'] as $key) {
            $nodes[$key] ??= $this->node($key, false, []);
        }
        $pending = DB::table('leave_requests')->where('tenant_id', $u->tenant_id)->where('employee_id', $emp->id)->where('status', 'pending')->count();
        $nodes['leave'] = $this->node('leave', true, $pending ? ["{$pending} leave request(s) are waiting for a decision."] : []);
        $running = DB::table('workflow_instances')->where('tenant_id', $u->tenant_id)->where('employee_id', $emp->id)->whereIn('status', ['running', 'waiting', 'paused', 'failed'])->count();
        $nodes['workflows'] = $this->node('workflows', true, $running ? ["{$running} workflow(s) about this person are still open."] : []);
        $gaps = DB::table('continuity_responsibilities')->where('tenant_id', $u->tenant_id)->where('owner_employee_id', $emp->id)->where('status', 'active')->whereIn('criticality', ['high', 'critical'])
            ->where(fn ($q) => $q->whereNull('backup_employee_id')->orWhere('backup_trained', false)->orWhere('documented', false))->count();
        $nodes['handover'] = $this->node('handover', true, $gaps ? ["{$gaps} high or critical responsibility(ies) have no trained backup or no documentation."] : []);
        $tasks = DB::table('lifecycle_tasks as t')->join('lifecycle_cases as c', 'c.id', '=', 't.case_id')->where('c.tenant_id', $u->tenant_id)->where('c.employee_id', $emp->id)->where('c.kind', 'offboarding')
            ->where('c.status', 'open')->where('t.status', 'pending')->whereNull('t.clearance_dept')->count();
        $nodes['tasks'] = $this->node('tasks', true, $tasks ? ["{$tasks} offboarding task(s) are still pending."] : []);

        // Derived nodes: blocked while anything they depend on is blocked; "ready" once every checked dependency is clear.
        foreach (self::DEPENDS as $key => $deps) {
            $deps = array_values(array_filter($deps, fn ($d) => isset($nodes[$d]) || isset(self::DEPENDS[$d])));
            $nodes[$key] = ['key' => $key, 'label' => self::LABELS[$key], 'checked' => true, 'state' => 'pending', 'blockers' => [], 'depends_on' => $deps];
        }
        $resolve = function (string $key) use (&$resolve, &$nodes): string {
            $n = $nodes[$key];
            if ($n['state'] !== 'pending') {
                return $n['state'];
            }
            $blockedBy = [];
            $uncheckedBy = [];
            foreach ($n['depends_on'] as $d) {
                $st = $resolve($d);
                if ($st === 'blocked') {
                    $blockedBy[] = $d;
                } elseif (in_array($st, ['not_checked', 'ready_unchecked'], true)) {
                    $uncheckedBy[] = $d;
                }
            }
            $nodes[$key]['state'] = $blockedBy ? 'blocked' : ($uncheckedBy ? 'ready_unchecked' : 'ready');
            $nodes[$key]['blocked_by'] = $blockedBy;
            $nodes[$key]['unchecked_by'] = $uncheckedBy;

            return $nodes[$key]['state'];
        };
        foreach (array_keys(self::DEPENDS) as $k) {
            $resolve($k);
        }
        foreach ($nodes as $k => &$n) {
            $n['depends_on'] = $n['depends_on'] ?? [];
            $n['blocked_by'] ??= [];
            $n['unchecked_by'] ??= [];
        }
        unset($n);

        // Work on blocked items whose own dependencies are not blocked: those are the ones that unlock the rest.
        $next = array_values(array_filter($nodes, fn ($n) => $n['state'] === 'blocked' && $n['blockers'] !== []));
        $unchecked = array_values(array_map(fn ($n) => $n['label'], array_filter($nodes, fn ($n) => $n['state'] === 'not_checked')));
        Audit::record('lifecycle.graph.view', 'lifecycle', 'employee:'.$emp->uuid, ['blocked' => count($next)]);

        return ['employee' => ['uuid' => $emp->uuid, 'name' => $emp->name, 'status' => $emp->status], 'nodes' => array_values($nodes), 'next_actions' => array_map(fn ($n) => ['key' => $n['key'], 'label' => $n['label'], 'blockers' => $n['blockers']], $next),
            'can_start_settlement' => ($nodes['settlement']['state'] ?? '') === 'ready', 'settlement_state' => $nodes['settlement']['state'] ?? null, 'not_checked_here' => $unchecked,
            'limitations' => 'This view is informational. Offboarding enforces only the departments it already enforces. "Not checked here" means no module reports its status yet, so it is neither clear nor blocked. A step that depends on an unchecked item is shown as "ready, with unchecked items", never as fully ready.'];
    }

    private function node(string $key, bool $checked, array $blockers): array
    {
        return ['key' => $key, 'label' => self::LABELS[$key] ?? ucfirst($key), 'checked' => $checked, 'state' => ! $checked ? 'not_checked' : ($blockers ? 'blocked' : 'clear'), 'blockers' => $blockers, 'depends_on' => []];
    }
}
