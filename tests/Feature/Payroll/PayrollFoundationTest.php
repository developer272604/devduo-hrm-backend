<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Models\EmployeeCompensation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class PayrollFoundationTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures;
    protected function setUp(): void { parent::setUp(); $this->bootTenant(); $this->signIn($this->admin); }

    private function config(): array
    {
        $group = $this->postJson('/api/v1/payroll/groups', ['name' => 'India monthly', 'code' => 'IN_MONTHLY', 'legal_entity_id' => $this->org['legal_entity'][0], 'country' => 'IN', 'currency' => 'INR', 'timezone' => 'Asia/Kolkata', 'frequency' => 'monthly'])->assertCreated()->json('uuid');
        $component = $this->postJson('/api/v1/payroll/components', ['name' => 'Base pay', 'code' => 'BASE', 'kind' => 'earning', 'currency' => 'INR', 'effective_from' => '2026-01-01'])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        return [$group, $component];
    }

    public function test_compensation_is_encrypted_scoped_and_requires_independent_approval(): void
    {
        [$group, $component] = $this->config(); $employee = $this->hire('Asha Rao');
        $checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $data = ['employee_uuid' => $employee->uuid, 'group_uuid' => $group, 'effective_from' => '2026-10-01', 'reason' => 'Annual revision', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['component_uuid' => $component, 'amount' => '35000.10']]];
        $uuid = $this->postJson('/api/v1/payroll/compensations', $data)->assertCreated()->json('uuid');
        $this->postJson('/api/v1/payroll/compensations', $data)->assertCreated()->assertJsonPath('uuid', $uuid);
        $this->assertDatabaseCount('employee_compensations', 1);
        $this->assertNotSame('35000.10', DB::table('compensation_lines')->value('amount'));
        $this->assertDatabaseHas('work_items', ['assignee_id' => $checker->id, 'source_entity' => 'compensation:'.$uuid, 'status' => 'open']);
        $this->postJson('/api/v1/payroll/compensations/'.$uuid.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->signIn($checker)->postJson('/api/v1/payroll/compensations/'.$uuid.'/decision', ['decision' => 'approved'])->assertOk();
        $this->context($checker->tenant_id);
        $this->assertSame('approved', EmployeeCompensation::first()->status);
        $this->assertDatabaseHas('work_items', ['source_entity' => 'compensation:'.$uuid, 'status' => 'completed']);
        $this->getJson('/api/v1/payroll/compensations')->assertOk()->assertJsonPath('data.0.lines.0.amount', '35000.10');
        $this->context($checker->tenant_id);
        $hr = $this->userWithRole('HR administrator', 'hr@alpha.test');
        $this->signIn($hr)->getJson('/api/v1/payroll/compensations')->assertForbidden();
    }

    public function test_cross_tenant_config_and_records_are_inaccessible(): void
    {
        [$group] = $this->config(); $other = $this->bootTenant('beta'); $this->signIn($other);
        $this->getJson('/api/v1/payroll/groups')->assertOk()->assertJsonCount(0, 'data');
        $this->context($other->tenant_id);
        $employee = $this->hire('Beta Person');
        $this->postJson('/api/v1/payroll/compensations', ['employee_uuid' => $employee->uuid, 'group_uuid' => $group, 'effective_from' => '2026-10-01', 'reason' => 'Cross tenant', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['component_uuid' => (string) Str::uuid(), 'amount' => '1.00']]])->assertNotFound();
    }

    public function test_formula_simulation_does_not_persist_financial_results_and_readiness_is_blocked(): void
    {
        $this->postJson('/api/v1/payroll/simulate', ['expression' => 'BASE / 3 * 3', 'variables' => ['BASE' => '100.01']])->assertOk()->assertJsonPath('result', '100.01')->assertJsonPath('persisted', false);
        $this->postJson('/api/v1/payroll/simulate', ['expression' => 'eval(1)', 'variables' => []])->assertUnprocessable();
        $this->getJson('/api/v1/payroll/readiness')->assertOk()->assertJsonPath('state', 'blocked');
        $this->assertDatabaseCount('employee_compensations', 0);
    }

    public function test_component_versions_cannot_overlap_and_currency_is_validated(): void
    {
        [$group, $component] = $this->config();
        $this->postJson('/api/v1/payroll/components', ['name' => 'New Base', 'code' => 'BASE', 'kind' => 'earning', 'currency' => 'INR', 'effective_from' => '2026-10-01'])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $employee = $this->hire('London Person', ['location' => 1]);
        $this->postJson('/api/v1/payroll/compensations', ['employee_uuid' => $employee->uuid, 'group_uuid' => $group, 'effective_from' => '2026-10-01', 'reason' => 'Wrong entity', 'idempotency_key' => (string) Str::uuid(), 'lines' => [['component_uuid' => $component, 'amount' => '1.00']]])->assertUnprocessable();
    }
}
