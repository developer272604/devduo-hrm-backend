<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Recruitment\Models\OfferDocument;
use App\Domain\Recruitment\Models\OfferTemplate;
use App\Domain\Recruitment\RecruitmentHousekeeping;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class OfferDocumentTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private User $checker;

    private const BODY = '<h1 onclick="steal()">Offer for {{candidate_name}}</h1><p style="x">Role {{ job_title }}, start {{start_date}}, valid until {{valid_until}} at {{company_name}}.</p><p>Pay: {{compensation_reference}}</p><script>alert(1)</script>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $this->signIn($this->admin);
    }

    private function template(string $body = self::BODY, string $code = 'standard')
    {
        return $this->postJson('/api/v1/recruitment/offer-templates', ['code' => $code, 'name' => 'Standard offer', 'body' => $body]);
    }

    private function approvedOffer(string $name = 'Asha Rao', string $email = 'asha@example.test'): array
    {
        $job = $this->postJson('/api/v1/recruitment/jobs', ['code' => 'E'.Str::upper(Str::random(4)), 'title' => 'Engineer', 'description' => 'Build.'])->assertCreated()->json('uuid');
        $req = $this->postJson('/api/v1/recruitment/requisitions', ['job_uuid' => $job, 'legal_entity_id' => $this->org['legal_entity'][0], 'department_id' => $this->org['department'][0], 'recruiter_id' => $this->admin->id,
            'hiring_manager_id' => $this->admin->id, 'openings' => 1, 'target_date' => '2026-12-01', 'kind' => 'new', 'reason' => 'Plan', 'budget_amount' => '1000', 'budget_currency' => 'inr', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('uuid');
        $this->signIn($this->checker)->postJson("/api/v1/recruitment/requisitions/$req/decision", ['decision' => 'open'])->assertOk();
        $this->signIn($this->admin);
        $cand = $this->postJson('/api/v1/recruitment/candidates', ['name' => $name, 'email' => $email, 'source' => 'Referral', 'privacy_notice_version' => '2026.1', 'consent_reference' => 'Consent 1',
            'consented_at' => '2026-10-01T10:00:00+05:30', 'retention_until' => '2027-04-01'])->assertCreated()->json('uuid');
        $app = $this->postJson('/api/v1/recruitment/applications', ['candidate_uuid' => $cand, 'requisition_uuid' => $req, 'source' => 'Referral'])->assertCreated()->json('uuid');
        $from = 'applied';
        foreach (['screening', 'assessment', 'interview', 'offer'] as $stage) {
            $this->postJson("/api/v1/recruitment/applications/$app/transition", ['stage' => $stage, 'expected_stage' => $from, 'status' => 'active', 'reason' => 'Progressing on job-related criteria', 'idempotency_key' => (string) Str::uuid()])->assertOk();
            $from = $stage;
        }
        $offer = $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Software engineer', 'start_date' => '2026-11-02', 'expires_on' => '2026-10-30', 'compensation_reference' => 'Band E3 secret-ref', 'currency' => 'inr'])->assertCreated()->json('uuid');
        $this->postJson("/api/v1/recruitment/offers/$offer/submit")->assertOk();
        $this->signIn($this->checker)->postJson("/api/v1/recruitment/offers/$offer/approve")->assertOk();
        $this->signIn($this->admin);

        return [$offer, $cand];
    }

    private function restricted(string $name, array $permissions): User
    {
        $this->context($this->admin->tenant_id);
        $r = Role::create(['name' => $name, 'data_scope' => 'tenant', 'system' => false]);
        $r->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return $this->userWithRole($name, strtolower(str_replace(' ', '', $name)).'@alpha.test');
    }

    public function test_template_validation_sanitizing_and_versioning(): void
    {
        $this->template('Hello {{candidate_name}} {{system("ls")}}')->assertUnprocessable();
        $this->template('Hello {{ secret_field }}')->assertUnprocessable();
        $this->template(self::BODY)->assertCreated();
        $this->template('<p>Second {{candidate_name}}</p>')->assertCreated();
        $this->context($this->admin->tenant_id);
        $first = OfferTemplate::where('version', 1)->firstOrFail();
        $this->assertSame('retired', $first->status);
        $this->assertStringNotContainsString('onclick', $first->body);
        $this->assertStringNotContainsString('<script', $first->body);
        $this->assertStringContainsString('&lt;script', $first->body);
        $this->assertSame('active', OfferTemplate::where('version', 2)->value('status'));
        $this->signIn($this->admin)->getJson('/api/v1/recruitment/offer-templates')->assertOk()->assertJsonCount(2, 'data')->assertJsonStructure(['merge_fields']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'offer_template.created']);

        $other = $this->restricted('Viewer only', ['recruitment.view']);
        $this->signIn($other)->getJson('/api/v1/recruitment/offer-templates')->assertForbidden();
        $this->postJson('/api/v1/recruitment/offer-templates', ['code' => 'x', 'name' => 'x', 'body' => 'x'])->assertForbidden();
    }

    public function test_approval_and_send_generate_immutable_snapshots_and_regeneration_appends(): void
    {
        $this->template()->assertCreated();
        [$offer] = $this->approvedOffer();
        $this->getJson("/api/v1/recruitment/offers/$offer/documents")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.trigger', 'approved');
        $this->postJson("/api/v1/recruitment/offers/$offer/send")->assertOk();
        $this->getJson("/api/v1/recruitment/offers/$offer/documents")->assertJsonCount(1, 'data'); // send reuses the approved snapshot

        $html = $this->get("/api/v1/recruitment/offers/$offer/documents/1/print")->assertOk();
        $this->assertStringContainsString('text/html', $html->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", $html->headers->get('Content-Security-Policy'));
        $body = $html->getContent();
        $this->assertStringContainsString('Offer for Asha Rao', $body);
        $this->assertStringContainsString('Role Software engineer, start 2026-11-02, valid until 2026-10-30 at Alpha', $body);
        $this->assertStringContainsString('Band E3 secret-ref', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->context($this->admin->tenant_id);
        $v1Hash = OfferDocument::where('version', 1)->value('sha256');
        $v1Raw = DB::table('recruitment_offer_documents')->where('version', 1)->value('content');
        $this->assertStringNotContainsString('Asha', $v1Raw, 'stored snapshot is encrypted');

        // New template version and regeneration: v1 is untouched, v2 is appended.
        $this->template('<p>Revised for {{candidate_name}}</p>')->assertCreated();
        $this->postJson("/api/v1/recruitment/offers/$offer/documents")->assertCreated()->assertJsonPath('version', 2)->assertJsonPath('template_version', 2);
        $this->assertStringContainsString('Revised for Asha Rao', $this->get("/api/v1/recruitment/offers/$offer/documents/2/print")->getContent());
        $this->assertStringContainsString('Offer for Asha Rao', $this->get("/api/v1/recruitment/offers/$offer/documents/1/print")->getContent());
        $this->context($this->admin->tenant_id);
        $this->assertSame($v1Hash, OfferDocument::where('version', 1)->value('sha256'));
        $this->assertSame(2, OfferDocument::count());
        $this->get("/api/v1/recruitment/offers/$offer/documents/9/print")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'offer_document.generated', 'resource' => $offer]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'offer_document.viewed', 'resource' => $offer]);
    }

    public function test_merge_values_are_escaped(): void
    {
        $this->template('<p>{{candidate_name}}</p>')->assertCreated();
        [$offer] = $this->approvedOffer('<script>alert("x")</script> & <img src=x onerror=1>', 'xss@example.test');
        $html = $this->get("/api/v1/recruitment/offers/$offer/documents/1/print")->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &amp; &lt;img', $html);
    }

    public function test_compensation_is_shown_only_with_payroll_compensation_view(): void
    {
        $this->template()->assertCreated();
        [$offer] = $this->approvedOffer();
        $hr = $this->restricted('Offer manager no pay', ['recruitment.view', 'recruitment.offer.manage']);
        $this->assertFalse($hr->allows('payroll.compensation.view'));
        $page = $this->signIn($hr)->get("/api/v1/recruitment/offers/$offer/documents/1/print")->assertOk()->getContent();
        $this->assertStringNotContainsString('secret-ref', $page);
        $this->assertStringContainsString('Compensation details are shared separately', $page);
        $this->assertStringContainsString('secret-ref', $this->signIn($this->admin)->get("/api/v1/recruitment/offers/$offer/documents/1/print")->getContent());
    }

    public function test_state_authorization_and_tenant_rules(): void
    {
        $this->postJson('/api/v1/recruitment/offers/'.Str::uuid().'/documents')->assertNotFound();
        [$offer] = $this->approvedOffer();
        $this->postJson("/api/v1/recruitment/offers/$offer/documents")->assertUnprocessable(); // no active template
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, OfferDocument::count());
        $this->template()->assertCreated();
        $none = $this->restricted('Nobody', ['people.view']);
        $this->signIn($none)->getJson("/api/v1/recruitment/offers/$offer/documents")->assertForbidden();
        $view = $this->restricted('Viewer only two', ['recruitment.view']);
        $this->signIn($view)->get("/api/v1/recruitment/offers/$offer/documents/1/print")->assertForbidden();

        $this->signIn($this->admin)->postJson("/api/v1/recruitment/offers/$offer/withdraw")->assertOk();
        $this->postJson("/api/v1/recruitment/offers/$offer/documents")->assertConflict(); // withdrawn offers get no new letter

        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson("/api/v1/recruitment/offers/$offer/documents")->assertNotFound();
        $this->postJson("/api/v1/recruitment/offers/$offer/documents")->assertNotFound();
        $this->get("/api/v1/recruitment/offers/$offer/documents/1/print")->assertNotFound();
    }

    public function test_no_template_means_no_automatic_document_and_draft_offers_are_rejected(): void
    {
        [$offer] = $this->approvedOffer();
        $this->postJson("/api/v1/recruitment/offers/$offer/send")->assertOk(); // existing workflow unchanged
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, OfferDocument::count());
    }

    public function test_anonymization_and_retention_purge_letter_snapshots_idempotently(): void
    {
        $this->template()->assertCreated();
        [$offer, $cand] = $this->approvedOffer();
        [$offer2, $cand2] = $this->approvedOffer('Ravi Two', 'ravi@example.test');
        $this->postJson("/api/v1/recruitment/candidates/$cand/anonymize", ['erasure_request' => true])->assertOk();
        $this->getJson("/api/v1/recruitment/offers/$offer/documents")->assertOk()->assertJsonPath('data.0.available', false);
        $this->get("/api/v1/recruitment/offers/$offer/documents/1/print")->assertNotFound();
        $this->postJson("/api/v1/recruitment/offers/$offer/documents")->assertConflict();
        $this->context($this->admin->tenant_id);
        $this->assertNull(DB::table('recruitment_offer_documents')->where('version', 1)->orderBy('id')->value('content'));

        DB::table('recruitment_candidates')->where('uuid', $cand2)->update(['retention_until' => '2026-01-01']);
        $housekeeping = app(RecruitmentHousekeeping::class);
        $this->assertSame(1, $housekeeping->run()['offer_documents_purged']);
        $this->assertSame(0, $housekeeping->run()['offer_documents_purged']);
        $this->assertSame(0, OfferDocument::whereNull('purged_at')->count());
    }
}
