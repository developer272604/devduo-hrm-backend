<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Recruitment\Models\Offer;
use App\Mail\CandidatePortalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CandidatePortalTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecruitment();
        Storage::fake('hrm_private');
        config(['recruitment.candidate_portal_enabled' => true]);
        config(['queue.default' => 'sync']);
        Mail::fake();
    }

    private function requestCode(): string
    {
        $this->postJson('/api/v1/careers/alpha/portal/code', ['email' => 'asha@example.test'])->assertStatus(202)->assertJsonMissingPath('code');

        return Mail::sent(CandidatePortalCode::class)->last()->code;
    }

    private function pdf(string $body = 'portal'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('candidate-cv.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n% $body\ntrailer<<>>\n%%EOF\n");
    }

    public function test_verified_candidate_session_has_only_own_public_status_and_code_is_single_use(): void
    {
        $candidate = $this->candidate();
        $this->apply($this->requisition(), $candidate);
        $this->apply($this->requisition(extra: ['confidential' => true]), $candidate);
        $this->apply($this->requisition(), $this->candidate('other@example.test'));
        $this->getJson('/api/v1/careers/alpha/portal')->assertUnauthorized(); // Employee auth alone grants no portal access.
        $code = $this->requestCode();
        $this->assertNotSame($code, DB::table('recruitment_portal_challenges')->value('token_hash'));
        $this->postJson('/api/v1/careers/alpha/portal/verify', ['email' => 'asha@example.test', 'code' => $code])->assertOk();
        $this->getJson('/api/v1/careers/alpha/portal')->assertOk()->assertJsonCount(1, 'applications')->assertJsonPath('applications.0.status', 'Under review')->assertJsonMissingPath('applications.0.requisition_id')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson('/api/v1/careers/alpha/portal/verify', ['email' => 'asha@example.test', 'code' => $code])->assertUnprocessable();
        $this->postJson('/api/v1/careers/alpha/portal/logout')->assertNoContent();
        $this->getJson('/api/v1/careers/alpha/portal')->assertUnauthorized();
    }

    public function test_wrong_browser_expiry_retention_and_cross_tenant_access_are_rejected(): void
    {
        $this->candidate();
        $code = $this->requestCode();
        $nonce = session('candidate_portal_browser');
        $this->withSession(['candidate_portal_browser' => 'wrong'])->postJson('/api/v1/careers/alpha/portal/verify', ['email' => 'asha@example.test', 'code' => $code])->assertUnprocessable();
        $this->withSession(['candidate_portal_browser' => $nonce]);
        DB::table('recruitment_portal_challenges')->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/careers/alpha/portal/verify', ['email' => 'asha@example.test', 'code' => $code])->assertUnprocessable();
        $code = $this->requestCode();
        $this->postJson('/api/v1/careers/alpha/portal/verify', ['email' => 'asha@example.test', 'code' => $code])->assertOk();
        $this->bootTenant('beta');
        $this->getJson('/api/v1/careers/beta/portal')->assertUnauthorized();
        DB::table('recruitment_candidates')->update(['retention_until' => '2026-10-06']);
        $this->getJson('/api/v1/careers/alpha/portal')->assertUnauthorized();
    }

    public function test_unknown_email_gets_same_generic_response_and_disabled_portal_sends_no_mail(): void
    {
        $unknown = $this->postJson('/api/v1/careers/alpha/portal/code', ['email' => 'missing@example.test'])->assertStatus(202)->json();
        Mail::assertNothingSent();
        $this->candidate();
        $known = $this->postJson('/api/v1/careers/alpha/portal/code', ['email' => 'asha@example.test'])->assertStatus(202)->json();
        $this->assertSame($unknown, $known);
        config(['recruitment.candidate_portal_enabled' => false]);
        $this->postJson('/api/v1/careers/alpha/portal/code', ['email' => 'asha@example.test'])->assertStatus(503);
        Mail::assertSentCount(1);
    }

    public function test_candidate_verification_never_logs_in_an_employee_and_session_expires(): void
    {
        $this->candidate();
        auth()->forgetGuards();
        $code = $this->requestCode();
        $this->postJson('/api/v1/careers/alpha/portal/verify', ['email' => 'asha@example.test', 'code' => $code])->assertOk();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->getJson('/api/v1/careers/alpha/portal')->assertOk();
        $this->travel(61)->minutes();
        $this->getJson('/api/v1/careers/alpha/portal')->assertUnauthorized();
    }

    public function test_candidate_withdrawal_preserves_history_and_cancels_interviews_once(): void
    {
        $candidate = $this->candidate();
        $application = $this->apply($this->requisition(), $candidate);
        $other = $this->apply($this->requisition(), $this->candidate('other@example.test'));
        $interview = $this->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $application, 'round' => 'Technical', 'starts_at' => '2026-10-08T10:00', 'ends_at' => '2026-10-08T11:00', 'timezone' => 'UTC', 'panelist_ids' => [$this->checker->id]])->assertCreated()->json('uuid');
        $code = $this->requestCode();
        $this->postJson('/api/v1/careers/alpha/portal/verify', ['email' => 'asha@example.test', 'code' => $code])->assertOk();
        $body = ['confirmed' => true, 'reason' => 'Plans changed'];
        $this->postJson('/api/v1/careers/alpha/portal/applications/'.$other.'/withdraw', $body)->assertNotFound();
        $path = '/api/v1/careers/alpha/portal/applications/'.$application.'/withdraw';
        $this->postJson($path, ['reason' => 'Plans changed'])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $offer = Offer::create(['application_id' => DB::table('recruitment_applications')->where('uuid', $application)->value('id'), 'version' => 1, 'status' => 'accepted', 'job_title' => 'Engineer', 'start_date' => '2026-12-01', 'created_by' => $this->admin->id]);
        $this->postJson($path, $body)->assertConflict();
        DB::table('recruitment_offers')->where('id', $offer->id)->update(['status' => 'sent']);
        $this->postJson($path, $body)->assertOk();
        $this->assertDatabaseHas('recruitment_offers', ['id' => $offer->id, 'status' => 'withdrawn']);
        $this->postJson($path, $body)->assertOk();
        $this->assertDatabaseHas('recruitment_applications', ['uuid' => $application, 'status' => 'withdrawn']);
        $this->assertDatabaseHas('recruitment_interviews', ['uuid' => $interview, 'status' => 'cancelled']);
        $this->assertDatabaseHas('work_items', ['source_entity' => 'scorecard:'.$interview.':'.$this->checker->id, 'status' => 'completed']);
        $this->assertSame(1, DB::table('recruitment_application_actions')->where('status', 'withdrawn')->count());
        $this->assertDatabaseHas('recruitment_application_actions', ['actor_id' => null, 'reason' => 'Plans changed', 'status' => 'withdrawn']);
    }

    public function test_candidate_offer_release_requires_independent_review_and_latest_terms(): void
    {
        $candidate = $this->candidate();
        $application = $this->apply($this->requisition(), $candidate);
        $this->context($this->admin->tenant_id);
        $offer = Offer::create(['application_id' => DB::table('recruitment_applications')->where('uuid', $application)->value('id'), 'version' => 1, 'status' => 'sent', 'job_title' => 'Engineer', 'start_date' => '2026-12-01', 'created_by' => $this->admin->id, 'compensation_reference' => 'Internal benchmark']);
        $releasePath = '/api/v1/recruitment/offers/'.$offer->uuid.'/candidate-release';
        $terms = ['content' => 'Candidate offer terms reviewed by HR.', 'confirmed' => true];
        $this->postJson($releasePath, $terms)->assertForbidden();
        $this->signIn($this->checker);
        $first = $this->postJson($releasePath, $terms)->assertCreated()->json('uuid');
        $latest = $this->postJson($releasePath, $terms)->assertCreated()->json('uuid');
        $this->assertStringNotContainsString('Candidate offer terms', DB::table('recruitment_offer_releases')->value('content'));
        $code = $this->requestCode();
        $this->postJson('/api/v1/careers/alpha/portal/verify', ['email' => 'asha@example.test', 'code' => $code])->assertOk();
        $this->getJson('/api/v1/careers/alpha/portal')->assertOk()->assertJsonPath('offers.0.content', $terms['content'])->assertJsonMissing(['Internal benchmark']);
        $path = '/api/v1/careers/alpha/portal/offers/'.$offer->uuid.'/respond';
        $this->postJson($path, ['decision' => 'accepted', 'release_uuid' => $first, 'confirmed' => true])->assertConflict();
        DB::table('recruitment_offers')->where('id', $offer->id)->update(['expires_on' => '2026-10-06']);
        $body = ['decision' => 'accepted', 'release_uuid' => $latest, 'confirmed' => true];
        $this->postJson($path, $body)->assertConflict();
        DB::table('recruitment_offers')->where('id', $offer->id)->update(['expires_on' => null]);
        $this->postJson($path, $body)->assertOk();
        $this->postJson($path, $body)->assertOk();
        $this->postJson($path, array_replace($body, ['decision' => 'declined']))->assertConflict();
        $this->assertDatabaseHas('recruitment_offers', ['id' => $offer->id, 'status' => 'accepted', 'candidate_response_release' => $latest]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'offer.candidate_accepted')->count());
        $this->context($this->admin->tenant_id);
        app(\App\Domain\Recruitment\OfferDocumentService::class)->purgeForCandidate(\App\Domain\Recruitment\Models\Candidate::where('uuid', $candidate)->firstOrFail());
        $this->getJson('/api/v1/careers/alpha/portal')->assertJsonCount(0, 'offers');
    }

    public function test_verified_candidate_uploads_resume_for_recruiter_review(): void
    {
        $candidate = $this->candidate();
        $this->apply($this->requisition(), $candidate);
        $code = $this->requestCode();
        $this->postJson('/api/v1/careers/alpha/portal/verify', ['email' => 'asha@example.test', 'code' => $code])->assertOk();

        $this->post('/api/v1/careers/alpha/portal/resumes', ['file' => $this->pdf()], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('source', 'candidate_portal')
            ->assertJsonPath('review_status', 'pending_review');

        $this->getJson('/api/v1/careers/alpha/portal')->assertOk()->assertJsonPath('resumes.0.review_status', 'pending_review');
        $this->assertDatabaseHas('recruitment_resumes', ['uploaded_by' => null, 'uploaded_source' => 'candidate_portal', 'review_status' => 'pending_review']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'resume.candidate_uploaded')->count());

        $this->signIn($this->admin)
            ->postJson('/api/v1/recruitment/candidates/'.$candidate.'/resumes/1/review', ['status' => 'available'])
            ->assertOk()
            ->assertJsonPath('review_status', 'available');
        $this->assertDatabaseHas('recruitment_resumes', ['uploaded_source' => 'candidate_portal', 'review_status' => 'available', 'reviewed_by' => $this->admin->id]);
    }
}
