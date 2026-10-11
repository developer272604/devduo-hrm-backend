<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Recruitment\Models\{Candidate, Requisition};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class RecruitmentFoundationTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures;
    protected function setUp(): void { parent::setUp(); $this->bootTenant(); $this->signIn($this->admin); }

    private function requisition(bool $confidential = false): string
    {
        $job = $this->postJson('/api/v1/recruitment/jobs', ['code' => 'ENGINEER', 'title' => 'Software engineer', 'description' => 'Build tested applications.'])->assertCreated()->json('uuid');
        return $this->postJson('/api/v1/recruitment/requisitions', ['job_uuid' => $job, 'legal_entity_id' => $this->org['legal_entity'][0], 'recruiter_id' => $this->admin->id, 'hiring_manager_id' => $this->admin->id, 'openings' => 2, 'target_date' => '2026-12-01', 'kind' => 'new', 'confidential' => $confidential, 'reason' => 'Approved staffing plan', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('uuid');
    }

    private function candidate(string $email = 'asha@example.test'): string
    {
        return $this->postJson('/api/v1/recruitment/candidates', ['name' => 'Asha Rao', 'email' => $email, 'source' => 'Referral', 'privacy_notice_version' => '2026.1', 'consent_reference' => 'Recorded consent reference 001', 'consented_at' => '2026-10-01T10:00:00+05:30', 'retention_until' => '2027-04-01'])->assertCreated()->json('uuid');
    }

    public function test_requisition_approval_multiple_applications_and_transition_history(): void
    {
        $checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $one = $this->requisition(); $two = $this->requisition(); $candidate = $this->candidate();
        $this->assertNotSame('asha@example.test', DB::table('recruitment_candidates')->value('email'));
        $this->postJson('/api/v1/recruitment/applications', ['candidate_uuid' => $candidate, 'requisition_uuid' => $one, 'source' => 'Referral'])->assertUnprocessable();
        $this->postJson('/api/v1/recruitment/requisitions/'.$one.'/decision', ['decision' => 'open'])->assertForbidden();
        $this->signIn($checker);
        foreach ([$one, $two] as $uuid) $this->postJson('/api/v1/recruitment/requisitions/'.$uuid.'/decision', ['decision' => 'open'])->assertOk();
        $this->signIn($this->admin);
        $data = ['candidate_uuid' => $candidate, 'requisition_uuid' => $one, 'source' => 'Referral'];
        $application = $this->postJson('/api/v1/recruitment/applications', $data)->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/applications', $data)->assertCreated()->assertJsonPath('uuid', $application);
        $this->postJson('/api/v1/recruitment/applications', array_replace($data, ['requisition_uuid' => $two]))->assertCreated();
        $this->assertDatabaseCount('recruitment_candidates', 1); $this->assertDatabaseCount('recruitment_applications', 2);
        $move = ['stage' => 'screening', 'expected_stage' => 'applied', 'status' => 'active', 'reason' => 'Review job-related skills', 'idempotency_key' => (string) Str::uuid()];
        $this->postJson('/api/v1/recruitment/applications/'.$application.'/transition', $move)->assertOk();
        $this->postJson('/api/v1/recruitment/applications/'.$application.'/transition', $move)->assertOk();
        $this->getJson('/api/v1/recruitment/applications/'.$application.'/history')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.from_stage', 'applied')->assertJsonPath('data.1.to_stage', 'screening');
        $this->postJson('/api/v1/recruitment/applications/'.$application.'/transition', array_replace($move, ['stage' => 'offer', 'expected_stage' => 'screening', 'idempotency_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->postJson('/api/v1/recruitment/applications/'.$application.'/transition', array_replace($move, ['idempotency_key' => (string) Str::uuid()]))->assertConflict();
    }

    public function test_confidential_and_cross_tenant_records_are_hidden(): void
    {
        $hr = $this->userWithRole('HR administrator', 'hr@alpha.test');
        $uuid = $this->requisition(true); $this->candidate();
        $this->signIn($hr)->getJson('/api/v1/recruitment/requisitions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/recruitment/candidates')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/recruitment/requisitions/'.$uuid.'/decision', ['decision' => 'open'])->assertNotFound();
        $other = $this->bootTenant('beta'); $this->signIn($other);
        $this->getJson('/api/v1/recruitment/requisitions')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/recruitment/requisitions/'.$uuid.'/decision', ['decision' => 'open'])->assertNotFound();
    }

    public function test_duplicate_candidate_needs_review_and_consent_is_mandatory(): void
    {
        $this->candidate();
        $data = ['name' => 'Asha', 'email' => 'ASHA@example.test', 'source' => 'Direct', 'privacy_notice_version' => 'v1', 'consent_reference' => 'recorded', 'consented_at' => '2026-10-01T00:00:00Z', 'retention_until' => '2027-04-01'];
        $this->postJson('/api/v1/recruitment/candidates', $data)->assertConflict();
        unset($data['consent_reference']); $data['email'] = 'different@example.test';
        $this->postJson('/api/v1/recruitment/candidates', $data)->assertUnprocessable();
        $this->assertDatabaseCount('recruitment_candidates', 1);
    }

    public function test_ordinary_employee_cannot_access_recruitment(): void
    {
        $user = $this->userWithRole('Team member', 'member@alpha.test');
        $this->signIn($user)->getJson('/api/v1/recruitment/candidates')->assertForbidden();
        $this->postJson('/api/v1/recruitment/jobs', ['code' => 'X', 'title' => 'X', 'description' => 'X'])->assertForbidden();
    }
}
