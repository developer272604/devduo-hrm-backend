<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Groups\GroupResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PayrollFixtures;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class GroupPeriodTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, PayrollFixtures;

    protected function setUp(): void { parent::setUp(); $this->bootTenant(); $this->signIn($this->admin); }

    public function test_employee_resolves_to_exactly_one_group_and_transfer_splits_the_period(): void
    {
        $a = $this->payrollGroup(); $b = $this->payrollGroup(['code' => 'IN_SECOND', 'name' => 'Second']);
        $e = $this->hire('Asha Rao'); $r = app(GroupResolver::class);
        $this->postJson('/api/v1/payroll/groups/'.$a->uuid.'/employees', ['employee_uuid' => $e->uuid, 'effective_from' => '2026-01-05'])->assertCreated();
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/groups/'.$b->uuid.'/employees', ['employee_uuid' => $e->uuid, 'effective_from' => '2026-09-16'])->assertCreated();
        $this->context($this->admin->tenant_id);
        $this->assertSame($a->id, $r->groupIdFor($e->id, '2026-09-15'));
        $this->assertSame($b->id, $r->groupIdFor($e->id, '2026-09-16'));
        $seg = $r->segments($e->id, '2026-09-01', '2026-09-30');
        $this->assertCount(2, $seg); $this->assertSame('2026-09-15', $seg[0]['to']);
        $this->assertSame([], $r->conflicts());
        // cannot start a group before an existing later one
        $this->postJson('/api/v1/payroll/groups/'.$a->uuid.'/employees', ['employee_uuid' => $e->uuid, 'effective_from' => '2026-09-16'])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->getJson('/api/v1/payroll/employees/'.$e->uuid.'/group?date=2026-09-20')->assertOk()->assertJsonPath('group.code', 'IN_SECOND');
    }

    public function test_group_must_match_legal_entity(): void
    {
        $g = $this->payrollGroup(); $london = $this->hire('Lon Don', ['location' => 1]);
        $this->postJson('/api/v1/payroll/groups/'.$g->uuid.'/employees', ['employee_uuid' => $london->uuid, 'effective_from' => '2026-01-05'])->assertUnprocessable();
    }

    public function test_calendar_period_generation_is_idempotent_and_state_machine_is_enforced(): void
    {
        $g = $this->payrollGroup();
        $cal = $this->postJson('/api/v1/payroll/groups/'.$g->uuid.'/calendars', ['name' => 'FY26', 'frequency' => 'monthly', 'effective_from' => '2026-01-01', 'rules' => ['payment_days_after_end' => 3]])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/groups/'.$g->uuid.'/calendars', ['name' => 'dup', 'frequency' => 'monthly', 'effective_from' => '2026-06-01'])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/groups/'.$g->uuid.'/calendars', ['name' => 'bad', 'frequency' => 'weekly', 'effective_from' => '2027-01-01'])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/calendars/'.$cal.'/generate', ['from' => '2026-01-01', 'to' => '2026-12-31'])->assertCreated()->assertJsonPath('created', 12);
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/calendars/'.$cal.'/generate', ['from' => '2026-01-01', 'to' => '2026-12-31'])->assertCreated()->assertJsonPath('created', 0)->assertJsonPath('skipped', 12);
        $this->context($this->admin->tenant_id);
        $list = $this->getJson('/api/v1/payroll/periods?group_uuid='.$g->uuid.'&from=2026-09-01&to=2026-09-30')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame('2026-10-03', $list['payment_date']); $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/periods/'.$list['uuid'].'/transition', ['to' => 'locked'])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/periods/'.$list['uuid'].'/transition', ['to' => 'input_open'])->assertOk()->assertJsonPath('state', 'input_open');
        $this->context($this->admin->tenant_id);
        $this->getJson('/api/v1/payroll/periods/'.$list['uuid'])->assertOk()->assertJsonCount(1, 'transitions');
        $this->assertDatabaseHas('audit_logs', ['action' => 'period.input_open']);
        // off-cycle and bonus periods may share the dates of the regular one
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/groups/'.$g->uuid.'/periods', ['period_type' => 'bonus', 'label' => 'Sep bonus', 'parent_period_uuid' => $list['uuid']])->assertCreated()->assertJsonPath('period_type', 'bonus');
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/payroll/groups/'.$g->uuid.'/periods', ['period_type' => 'bonus', 'label' => 'Sep bonus 2', 'parent_period_uuid' => $list['uuid']])->assertCreated()->assertJsonPath('cycle_no', 2);
    }

    public function test_hr_without_configure_permission_is_forbidden_and_tenants_are_isolated(): void
    {
        $this->payrollGroup();
        $hr = $this->userWithRole('HR administrator', 'hr@alpha.test');
        $this->signIn($hr)->getJson('/api/v1/payroll/groups')->assertForbidden();
        $other = $this->bootTenant('beta'); $this->signIn($other);
        $this->getJson('/api/v1/payroll/groups')->assertOk()->assertJsonCount(0, 'data');
    }
}
