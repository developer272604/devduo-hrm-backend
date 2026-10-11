<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Lifecycle\Models\{LifecycleCase, LifecycleTask};
use App\Domain\People\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\{LifecycleFixtures, PeopleFixtures};
use Tests\TestCase;

class HandoverOnboardingTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, LifecycleFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycleSetup();
    }

    private function handedOverEmployee(): string
    {
        $job = $this->postJson('/api/v1/recruitment/jobs', ['code' => 'ENG'.Str::upper(Str::random(4)), 'title' => 'Software engineer', 'description' => 'Build tested applications.'])->assertCreated()->json('uuid');
        $req = $this->postJson('/api/v1/recruitment/requisitions', ['job_uuid' => $job, 'legal_entity_id' => $this->org['legal_entity'][0], 'department_id' => $this->org['department'][0], 'recruiter_id' => $this->admin->id,
            'hiring_manager_id' => $this->admin->id, 'openings' => 1, 'target_date' => '2026-12-01', 'kind' => 'new', 'reason' => 'Approved staffing plan', 'budget_amount' => '1200000', 'budget_currency' => 'inr',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('uuid');
        $this->signIn($this->checker)->postJson('/api/v1/recruitment/requisitions/'.$req.'/decision', ['decision' => 'open'])->assertOk();
        $this->signIn($this->admin);
        $cand = $this->postJson('/api/v1/recruitment/candidates', ['name' => 'Asha Rao', 'email' => 'asha@example.test', 'phone' => '+91 90000 00000', 'source' => 'Referral', 'privacy_notice_version' => '2026.1',
            'consent_reference' => 'Consent ref 001', 'consented_at' => '2026-10-01T10:00:00+05:30', 'retention_until' => '2027-04-01'])->assertCreated()->json('uuid');
        $app = $this->postJson('/api/v1/recruitment/applications', ['candidate_uuid' => $cand, 'requisition_uuid' => $req, 'source' => 'Referral'])->assertCreated()->json('uuid');
        $from = 'applied';
        foreach (['screening', 'assessment', 'interview', 'offer'] as $stage) {
            $this->postJson('/api/v1/recruitment/applications/'.$app.'/transition', ['stage' => $stage, 'expected_stage' => $from, 'status' => 'active', 'reason' => 'Progressing on job-related criteria', 'idempotency_key' => (string) Str::uuid()])->assertOk();
            $from = $stage;
        }
        $offer = $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Software engineer', 'start_date' => '2026-11-02', 'compensation_reference' => 'Band E3', 'currency' => 'inr'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/submit')->assertOk();
        $this->signIn($this->checker)->postJson('/api/v1/recruitment/offers/'.$offer.'/approve')->assertOk();
        $this->signIn($this->admin)->postJson('/api/v1/recruitment/offers/'.$offer.'/send')->assertOk();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/accept')->assertOk();

        return $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', ['assignment' => ['department_id' => $this->org['department'][0], 'designation_id' => $this->org['designation'][0],
            'employee_type_id' => $this->org['employee_type'][0], 'location_id' => $this->org['location'][0]]])->assertCreated()->json('employee_uuid');
    }

    public function test_recruitment_handover_starts_onboarding_automatically(): void
    {
        $this->publishedTemplate('onboarding', [['title' => 'Prepare laptop', 'owner_type' => 'it', 'owner_user_id' => $this->checker->id, 'due_offset_days' => -3],
            ['title' => 'Collect bank details', 'owner_type' => 'hr', 'due_offset_days' => 1, 'requires_document' => true]]);
        $employeeUuid = $this->handedOverEmployee();

        $this->context($this->admin->tenant_id);
        $employee = Employee::where('uuid', $employeeUuid)->firstOrFail();
        $this->assertSame('preboarding', $employee->status);
        $case = LifecycleCase::where('employee_id', $employee->id)->where('kind', 'onboarding')->firstOrFail();
        $this->assertSame('recruitment_handover', $case->source);
        $this->assertSame('2026-11-02', $case->anchor_date->toDateString());
        $this->assertSame(2, LifecycleTask::where('case_id', $case->id)->count());
        $this->assertSame('2026-10-30', LifecycleTask::where('title', 'Prepare laptop')->first()->due_date->toDateString());

        // Pre-joiner status for HR, plus the offer handover being retried does not duplicate the case.
        $this->getJson('/api/v1/lifecycle/preboarders')->assertOk()->assertJsonPath('0.employee_uuid', $employeeUuid)->assertJsonPath('0.case.kind', 'onboarding');
        $this->getJson('/api/v1/lifecycle/dashboard')->assertOk()->assertJsonPath('open_onboarding', 1);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, LifecycleCase::where('employee_id', $employee->id)->count());
    }
}
