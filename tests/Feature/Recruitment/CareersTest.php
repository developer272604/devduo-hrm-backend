<?php

namespace Tests\Feature\Recruitment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CareersTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecruitment();
    }

    private function posting(): string
    {
        $req = $this->requisition();
        $id = $this->postJson('/api/v1/recruitment/postings', ['requisition_uuid' => $req, 'title' => 'Engineer', 'description' => 'Build software', 'channel' => 'external'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/postings/'.$id.'/publish')->assertOk();

        return $id;
    }

    private function enable(string $id): void
    {
        $this->putJson('/api/v1/recruitment/postings/'.$id.'/public', ['public_enabled' => true, 'privacy_notice' => 'Recruitment review only. Retained for 90 days.', 'notice_version' => 'v1', 'retention_days' => 90])->assertOk();
    }

    public function test_public_intake_is_separate_until_review_and_never_exposes_candidate_matches(): void
    {
        $id = $this->posting();
        $this->getJson('/api/v1/careers/alpha/jobs')->assertOk()->assertJsonCount(0, 'jobs.data');
        $this->enable($id);
        auth()->forgetGuards();
        $this->getJson('/api/v1/careers/alpha/jobs')->assertOk()->assertJsonCount(1, 'jobs.data')->assertJsonMissingPath('jobs.data.0.requisition_id');
        $data = ['name' => 'Applicant', 'email' => 'applicant@example.test', 'consent' => true, 'notice_version' => 'v1'];
        $this->postJson('/api/v1/careers/alpha/jobs/'.$id.'/apply', $data)->assertStatus(202)->assertJsonMissingPath('uuid');
        $this->assertDatabaseCount('recruitment_candidates', 0);
        $this->assertStringNotContainsString('applicant@example.test', DB::table('recruitment_submissions')->value('payload'));
        $this->signIn($this->admin);
        $submission = $this->getJson('/api/v1/recruitment/submissions')->assertOk()->json('data.0.uuid');
        $this->postJson('/api/v1/recruitment/submissions/'.$submission.'/review', ['decision' => 'accept', 'identity_reviewed' => false])->assertUnprocessable();
        $this->postJson('/api/v1/recruitment/submissions/'.$submission.'/review', ['decision' => 'accept', 'identity_reviewed' => true])->assertOk();
        $this->assertDatabaseCount('recruitment_candidates', 1);
        $this->assertDatabaseCount('recruitment_applications', 1);
        $this->postJson('/api/v1/recruitment/submissions/'.$submission.'/review', ['decision' => 'accept', 'identity_reviewed' => true])->assertConflict();
        $this->postJson('/api/v1/careers/alpha/jobs/'.$id.'/apply', $data)->assertStatus(202);
        $duplicate = $this->getJson('/api/v1/recruitment/submissions')->json('data.0.uuid');
        $this->postJson('/api/v1/recruitment/submissions/'.$duplicate.'/review', ['decision' => 'accept', 'identity_reviewed' => true])->assertConflict();
        $this->assertDatabaseCount('recruitment_candidates', 1);
    }

    public function test_visibility_consent_scope_and_expiry_controls(): void
    {
        $id = $this->posting();
        $this->enable($id);
        $data = ['name' => 'Applicant', 'email' => 'applicant@example.test', 'consent' => true, 'notice_version' => 'old'];
        $this->postJson('/api/v1/careers/alpha/jobs/'.$id.'/apply', $data)->assertConflict();
        $this->postJson('/api/v1/careers/alpha/jobs/'.$id.'/apply', array_replace($data, ['notice_version' => 'v1', 'consent' => false]))->assertUnprocessable();
        DB::table('recruitment_requisitions')->update(['confidential' => true]);
        $this->getJson('/api/v1/careers/alpha/jobs')->assertJsonCount(0, 'jobs.data');
        $this->postJson('/api/v1/careers/alpha/jobs/'.$id.'/apply', array_replace($data, ['notice_version' => 'v1']))->assertNotFound();
        DB::table('recruitment_requisitions')->update(['confidential' => false]);
        DB::table('recruitment_postings')->update(['closes_on' => '2026-10-06']);
        $this->getJson('/api/v1/careers/alpha/jobs')->assertJsonCount(0, 'jobs.data');
        $other = $this->bootTenant('beta');
        $this->signIn($other)->putJson('/api/v1/recruitment/postings/'.$id.'/public', ['public_enabled' => false])->assertNotFound();
        $this->getJson('/api/v1/careers/beta/jobs')->assertJsonCount(0, 'jobs.data');
        $this->postJson('/api/v1/careers/beta/jobs/'.$id.'/apply', array_replace($data, ['notice_version' => 'v1']))->assertNotFound();
    }

    public function test_rate_limits_permission_gates_and_expired_submission_cleanup(): void
    {
        $id = $this->posting();
        $this->enable($id);
        $data = ['name' => 'Applicant', 'email' => 'applicant@example.test', 'consent' => true, 'notice_version' => 'v1'];
        for ($i = 0; $i < 5; $i++) $this->postJson('/api/v1/careers/alpha/jobs/'.$id.'/apply', $data)->assertStatus(202);
        $this->postJson('/api/v1/careers/alpha/jobs/'.$id.'/apply', $data)->assertStatus(429);
        $this->context($this->admin->tenant_id);
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $this->signIn($member)->getJson('/api/v1/recruitment/submissions')->assertForbidden();
        $this->putJson('/api/v1/recruitment/postings/'.$id.'/public', ['public_enabled' => false])->assertForbidden();
        $this->signIn($this->admin);
        $uuid = $this->getJson('/api/v1/recruitment/submissions')->json('data.0.uuid');
        DB::table('recruitment_submissions')->update(['expires_at' => now()->subDay()]);
        $this->getJson('/api/v1/recruitment/submissions')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/recruitment/submissions/'.$uuid.'/review', ['decision' => 'reject'])->assertConflict();
        $this->context($this->admin->tenant_id);
        $tenant = \App\Models\Tenant::findOrFail($this->admin->tenant_id);
        $settings = $tenant->settings;
        $settings['modules'] = array_values(array_diff($settings['modules'], ['recruitment']));
        $tenant->update(['settings' => $settings]);
        $this->artisan('recruitment:housekeeping', ['--tenant' => $tenant->id])->assertSuccessful();
        $this->assertDatabaseCount('recruitment_submissions', 0);
        $this->getJson('/api/v1/careers/alpha/jobs')->assertNotFound();
    }
}
