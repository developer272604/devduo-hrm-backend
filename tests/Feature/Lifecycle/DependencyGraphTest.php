<?php

namespace Tests\Feature\Lifecycle;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

class DependencyGraphTest extends PerformanceTestCase
{
    private function graph($employee)
    {
        return $this->getJson('/api/v1/lifecycle/employees/'.$employee->uuid.'/dependency-graph');
    }

    private function node(array $g, string $key): array
    {
        return collect($g['nodes'])->firstWhere('key', $key);
    }

    private function asset($employee): void
    {
        DB::table('assets')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'identifier' => 'LT-'.Str::random(5), 'name' => 'Laptop', 'category' => 'it', 'status' => 'assigned',
            'employee_id' => $employee->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_clear_employee_is_not_called_ready_while_some_departments_are_unchecked(): void
    {
        $g = $this->signIn($this->admin)->graph($this->emp1)->assertOk()->json();
        $this->assertSame('clear', $this->node($g, 'assets')['state']);
        $this->assertSame('clear', $this->node($g, 'expenses')['state']);
        $this->assertSame('not_checked', $this->node($g, 'loans')['state']); // no module reports loans yet
        $this->assertSame('not_checked', $this->node($g, 'payroll')['state']);
        $settlement = $this->node($g, 'settlement');
        $this->assertSame('ready_unchecked', $settlement['state']); // nothing blocks it, but two inputs are unknown
        $this->assertEqualsCanonicalizing(['loans', 'payroll'], $settlement['unchecked_by']);
        $this->assertFalse($g['can_start_settlement']);
        $this->assertSame('ready_unchecked', $g['settlement_state']);
        $this->assertEqualsCanonicalizing(['Loans and advances', 'Payroll inputs'], $g['not_checked_here']);
        $this->assertSame([], $g['next_actions']);
        $this->assertStringContainsString('never as fully ready', $g['limitations']);
    }

    public function test_blockers_propagate_to_settlement_and_access_removal(): void
    {
        $this->asset($this->emp1);
        $g = $this->signIn($this->admin)->graph($this->emp1)->assertOk()->json();
        $assets = $this->node($g, 'assets');
        $this->assertSame('blocked', $assets['state']);
        $this->assertStringContainsString('1 assigned or lost asset', $assets['blockers'][0]);
        $this->assertSame(['assets'], $this->node($g, 'settlement')['blocked_by']);
        $this->assertSame('blocked', $this->node($g, 'settlement')['state']);
        $this->assertContains('settlement', $this->node($g, 'access')['blocked_by']);
        $this->assertSame('blocked', $this->node($g, 'access')['state']);
        $this->assertSame(['assets'], array_column($g['next_actions'], 'key')); // the only item you can act on directly
        $this->assertFalse($g['can_start_settlement']);
    }

    public function test_leave_workflows_handover_and_exit_tasks_each_show_their_own_blockers(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $tenant = $this->admin->tenant_id;
        $type = DB::table('leave_types')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'name' => 'Casual', 'code' => 'CL', 'category' => 'paid', 'unit' => 'day', 'tracks_balance' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leave_requests')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'employee_id' => $this->emp1->id, 'leave_type_id' => $type, 'status' => 'pending', 'start_date' => '2026-10-20', 'end_date' => '2026-10-21', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('leave_requests')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'employee_id' => $this->emp1->id, 'leave_type_id' => $type, 'status' => 'approved', 'start_date' => '2026-10-22', 'end_date' => '2026-10-22', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workflow_definitions')->insert(['id' => 501, 'uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'name' => 'Exit', 'draft' => '{}', 'revision' => 1, 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workflow_versions')->insert(['id' => 501, 'tenant_id' => $tenant, 'definition_id' => 501, 'number' => 1, 'spec' => '{}', 'published_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workflow_instances')->insert(['tenant_id' => $tenant, 'uuid' => (string) Str::uuid(), 'definition_id' => 501, 'version_id' => 501, 'employee_id' => $this->emp1->id, 'initiated_by' => $this->admin->id,
            'idempotency_key' => 'k1', 'status' => 'running', 'context' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('continuity_responsibilities')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'owner_employee_id' => $this->emp1->id, 'title' => 'Vendor calls', 'criticality' => 'critical', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $case = DB::table('lifecycle_cases')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'kind' => 'offboarding', 'employee_id' => $this->emp1->id, 'status' => 'open', 'anchor_date' => '2026-10-30', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('lifecycle_tasks')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'case_id' => $case, 'group_name' => 'HR', 'title' => 'Exit interview', 'owner_type' => 'hr', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        $g = $this->signIn($this->admin)->graph($this->emp1)->assertOk()->json();
        $this->assertStringContainsString('1 leave request(s)', $this->node($g, 'leave')['blockers'][0]); // only the pending one waits for a decision
        $this->assertStringContainsString('1 workflow(s)', $this->node($g, 'workflows')['blockers'][0]);
        $this->assertStringContainsString('1 high or critical', $this->node($g, 'handover')['blockers'][0]);
        $this->assertStringContainsString('1 offboarding task(s)', $this->node($g, 'tasks')['blockers'][0]);
        $this->assertEqualsCanonicalizing(['leave', 'workflows'], $this->node($g, 'settlement')['blocked_by']);
        $this->assertEqualsCanonicalizing(['handover', 'tasks', 'settlement'], $this->node($g, 'access')['blocked_by']);
        $this->assertEqualsCanonicalizing(['leave', 'workflows', 'handover', 'tasks'], array_column($g['next_actions'], 'key'));
    }

    public function test_a_manager_sees_only_their_reports_and_others_are_refused(): void
    {
        $this->actAs($this->mgr)->graph($this->emp1)->assertForbidden(); // the default Manager role does not include lifecycle.view
        $role = DB::table('roles')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'name' => 'Exit coordinator', 'description' => 'x', 'data_scope' => 'direct_reports', 'system' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('key', 'lifecycle.view')->value('id')]);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $this->userOf($this->mgr)->id]);
        $this->actAs($this->mgr)->graph($this->emp1)->assertOk();
        $this->actAs($this->mgr)->graph($this->outsider)->assertNotFound(); // outside the coordinator's reach
        $this->actAs($this->emp1)->graph($this->emp2)->assertForbidden();
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson('/api/v1/lifecycle/employees/'.$this->emp1->uuid.'/dependency-graph')->assertNotFound();
    }
}
