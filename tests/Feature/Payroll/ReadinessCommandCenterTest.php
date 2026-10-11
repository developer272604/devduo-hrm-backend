<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Models\EmployeeCompensation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class ReadinessCommandCenterTest extends TestCase
{
    use PayrollFixtures, PeopleFixtures, RefreshDatabase, SecurityTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->signIn($this->admin);
    }

    private function check(array $json, string $key): array
    {
        return collect($json['checks'])->firstWhere('key', $key) ?? $this->fail('Check '.$key.' missing');
    }

    public function test_without_groups_readiness_is_blocked_with_an_explicit_issue(): void
    {
        $r = $this->getJson('/api/v1/payroll/readiness')->assertOk()->assertJsonPath('state', 'blocked')->assertJsonPath('status', 'BLOCKED');
        $this->assertSame('no_payroll_groups', $r->json('issues.0.code'));
    }

    public function test_group_without_period_is_blocked_and_with_data_gaps_lists_each_open_condition(): void
    {
        $group = $this->payrollGroup();
        $this->getJson('/api/v1/payroll/readiness?group='.$group->uuid)->assertOk()->assertJsonPath('state', 'blocked')->assertJsonPath('checks.0.key', 'payroll_period');

        $this->context($this->admin->tenant_id);
        $period = $this->payrollPeriod($group, '2026-10-01', '2026-10-31');
        $asha = $this->hire('Asha Rao');
        $ben = $this->hire('Ben Cole');
        $this->mapToGroup($asha, $group);
        $this->mapToGroup($ben, $group);

        $json = $this->getJson('/api/v1/payroll/readiness?group='.$group->uuid.'&period='.$period->uuid)->assertOk()->assertJsonPath('state', 'blocked')->json();
        $this->assertSame(2, $json['employees_expected']);
        $this->assertSame(0, $json['employees_ready']);
        $comp = $this->check($json, 'compensation_missing');
        $this->assertSame('open', $comp['state']);
        $this->assertSame(2, $comp['count']);
        $this->assertSame('blocking', $comp['severity']);
        $this->assertNotEmpty($comp['action']['href']);
        $this->assertSame(2, $this->check($json, 'bank_missing')['count']);

        $this->context($this->admin->tenant_id);
        // Give Asha approved compensation (with a base pay) and a bank account: her conditions resolve, Ben's stay open.
        $c = EmployeeCompensation::create(['employee_id' => $asha->id, 'payroll_group_id' => $group->id, 'effective_from' => '2026-01-01', 'status' => 'approved', 'reason' => 'x', 'created_by' => $this->admin->id,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'currency' => 'INR', 'base_pay' => '50000.00']);
        DB::table('employee_bank_accounts')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $asha->tenant_id, 'person_id' => $asha->person_id, 'account_holder' => 'Asha Rao', 'bank_name' => 'Test Bank', 'country' => 'IN', 'currency' => 'INR',
            'details_encrypted' => 'x', 'account_last4' => '1234', 'is_primary' => true, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $json = $this->getJson('/api/v1/payroll/readiness?group='.$group->uuid.'&period='.$period->uuid)->assertOk()->json();
        $this->assertSame(1, $this->check($json, 'compensation_missing')['count']);
        $this->assertSame(1, $this->check($json, 'bank_missing')['count']);
        $this->assertSame(Str::of($ben->uuid)->toString(), $this->check($json, 'bank_missing')['sample'][0]['employee_uuid']);
        $this->assertSame('state is derived from explicit open conditions only; there is no score.', strtolower($json['rule']));

        // Currency conflict is detected
        $this->context($this->admin->tenant_id);
        $c->update(['currency' => 'USD']);
        $json = $this->getJson('/api/v1/payroll/readiness?group='.$group->uuid.'&period='.$period->uuid)->json();
        $this->assertSame(1, $this->check($json, 'currency_conflict')['count']);

        // Map by group and by department
        $map = $this->getJson('/api/v1/payroll/readiness/map?by=payroll_group')->assertOk()->json('data');
        $this->assertSame('blocked', $map[0]['state']);
        $dept = $this->getJson('/api/v1/payroll/readiness/map?by=department')->assertOk()->json('data');
        $this->assertSame(2, $dept[0]['employees']);
    }

    public function test_command_center_shows_runs_issues_and_hides_money_without_results_permission(): void
    {
        $group = $this->payrollGroup();
        $run = $this->payrollRun($group, $this->payrollPeriod($group));
        $employee = $this->hire('Asha Rao');
        $this->calc($run, $employee);
        DB::table('payroll_validation_issues')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $employee->tenant_id, 'run_id' => $run->id, 'code' => 'negative_net', 'severity' => 'blocking', 'message' => 'x', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        $center = $this->getJson('/api/v1/payroll/command-center')->assertOk()->assertJsonPath('blocking_errors.count', 1)->assertJsonPath('money_visible', true)->json();
        $this->assertSame($run->uuid, $center['processing']['active_runs'][0]['uuid']);
        $this->assertSame('62899.50', $center['processing']['active_runs'][0]['control_totals']['net_total']);
        $this->assertSame('70000.00', $center['processing']['active_runs'][0]['control_totals']['gross_total']);

        $viewer = $this->userWithPermissions('ops@alpha.test', ['payroll.view']);
        $this->signIn($viewer)->getJson('/api/v1/payroll/command-center')->assertOk()->assertJsonPath('money_visible', false)->assertJsonPath('processing.active_runs.0.control_totals', null);

        $nobody = $this->userWithPermissions('nobody@alpha.test', ['people.view']);
        $this->signIn($nobody)->getJson('/api/v1/payroll/command-center')->assertForbidden();
        $this->signIn($nobody)->getJson('/api/v1/payroll/readiness')->assertForbidden();
    }

    public function test_other_tenants_see_nothing(): void
    {
        $group = $this->payrollGroup();
        $other = $this->bootTenant('beta');
        $this->signIn($other)->getJson('/api/v1/payroll/readiness?group='.$group->uuid)->assertNotFound();
    }
}
