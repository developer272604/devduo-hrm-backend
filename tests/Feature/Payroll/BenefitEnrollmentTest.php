<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\Models\EmployeeBenefitEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class BenefitEnrollmentTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        Carbon::setTestNow('2026-10-11 12:00:00');
        $this->signIn($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_eligible_employee_enrolls_during_window_and_independent_admin_decides(): void
    {
        $employee = $this->hire('Asha Rao', ['role' => 'Team member', 'email' => 'asha-benefit@alpha.test']);
        $plan = $this->postJson('/api/v1/benefits/plans', ['code' => 'HEALTH_CORE', 'name' => 'Health Plan', 'category' => 'insurance', 'country' => 'IN', 'currency' => 'INR',
            'eligibility' => ['worker_types' => ['employee'], 'countries' => ['IN']], 'contributions' => ['employee_fixed' => '150.00', 'employer_fixed' => '850.00'], 'effective_from' => '2026-01-01'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/benefits/plans/'.$plan.'/activate')->assertOk()->assertJsonPath('status', 'active');
        $window = $this->postJson('/api/v1/benefits/plans/'.$plan.'/windows', ['name' => 'October enrollment', 'opens_on' => '2026-10-01', 'closes_on' => '2026-10-31', 'effective_on' => '2026-11-01'])->assertCreated()->json('uuid');
        $member = User::where('email', 'asha-benefit@alpha.test')->firstOrFail();
        $this->context($this->admin->tenant_id);
        $this->signIn($member)->getJson('/api/v1/benefits/available')->assertOk()->assertJsonPath('data.0.uuid', $plan);
        $enrollment = $this->postJson('/api/v1/benefits/plans/'.$plan.'/enroll', ['window_uuid' => $window])->assertCreated()->assertJsonPath('status', 'pending')->json('uuid');
        $this->context($member->tenant_id);
        $this->assertSame(1, EmployeeBenefitEnrollment::count());
        $this->getJson('/api/v1/benefits/me')->assertOk()->assertJsonPath('data.0.status', 'pending')->assertJsonMissing(['employee_contribution' => '150.00']);
        $this->context($this->admin->tenant_id);
        $this->signIn($member);
        $this->postJson('/api/v1/benefits/enrollments/'.$enrollment.'/decision', ['decision' => 'approved'])->assertForbidden();
        $this->context($this->admin->tenant_id);
        $this->signIn($this->admin)->getJson('/api/v1/benefits/enrollments')->assertOk()->assertJsonPath('data.0.uuid', $enrollment);
        $this->postJson('/api/v1/benefits/enrollments/'.$enrollment.'/decision', ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'active');
        $this->context($this->admin->tenant_id);
        $this->assertSame('150.00', EmployeeBenefitEnrollment::first()->employee_contribution);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.benefit_enrollment.approved']);
        $this->assertDatabaseHas('work_items', ['source_module' => 'payroll', 'source_entity' => 'benefit:'.$enrollment, 'status' => 'completed']);

        $this->context($this->admin->tenant_id);
        $this->signIn($member)->postJson('/api/v1/benefits/me/'.$enrollment.'/termination', ['effective_to' => '2026-11-30'])
            ->assertOk()->assertJsonPath('status', 'termination_pending');
        $this->context($this->admin->tenant_id);
        $this->signIn($this->admin)->postJson('/api/v1/benefits/enrollments/'.$enrollment.'/termination-decision', ['decision' => 'rejected'])
            ->assertOk()->assertJsonPath('status', 'active');
        $this->context($this->admin->tenant_id);
        $this->assertNull(EmployeeBenefitEnrollment::first()->effective_to);
        $this->signIn($member)->postJson('/api/v1/benefits/me/'.$enrollment.'/termination', ['effective_to' => '2026-12-31'])
            ->assertOk()->assertJsonPath('status', 'termination_pending');
        $this->context($this->admin->tenant_id);
        $this->signIn($this->admin)->postJson('/api/v1/benefits/enrollments/'.$enrollment.'/termination-decision', ['decision' => 'approved'])
            ->assertOk()->assertJsonPath('status', 'ended');
        $this->context($this->admin->tenant_id);
        $this->assertSame('2026-12-31', substr((string) EmployeeBenefitEnrollment::first()->effective_to, 0, 10));
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.benefit_termination.approved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.benefit_termination.rejected']);
        $this->assertSame(2, DB::table('work_items')->where('source_module', 'payroll')->where('source_entity', 'benefit_termination:'.$enrollment)->where('status', 'completed')->count());
    }
}
