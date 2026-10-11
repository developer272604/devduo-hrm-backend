<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\People\Models\Employee;
use App\Domain\Recruitment\Operations\RecruitmentEmployee360Provider;
use App\Domain\Recruitment\Operations\RecruitmentInsightProvider;
use App\Domain\Recruitment\Operations\RecruitmentQualityRules;
use App\Domain\Recruitment\RecruitmentHousekeeping;
use App\Domain\Recruitment\RecruitmentNotify;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class RecruitmentCoreTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private User $checker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $this->signIn($this->admin);
    }

    private function requisition(int $openings = 1, array $extra = []): string
    {
        $job = $this->postJson('/api/v1/recruitment/jobs', ['code' => 'ENG'.Str::upper(Str::random(4)), 'title' => 'Software engineer', 'description' => 'Build tested applications.'])->assertCreated()->json('uuid');
        $uuid = $this->postJson('/api/v1/recruitment/requisitions', $extra + ['job_uuid' => $job, 'legal_entity_id' => $this->org['legal_entity'][0], 'department_id' => $this->org['department'][0], 'recruiter_id' => $this->admin->id,
            'hiring_manager_id' => $this->admin->id, 'openings' => $openings, 'target_date' => '2026-12-01', 'kind' => 'new', 'reason' => 'Approved staffing plan', 'budget_amount' => '1200000', 'budget_currency' => 'inr',
            'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('uuid');
        $this->signIn($this->checker)->postJson('/api/v1/recruitment/requisitions/'.$uuid.'/decision', ['decision' => 'open'])->assertOk();
        $this->signIn($this->admin);

        return $uuid;
    }

    private function candidate(string $email = 'asha@example.test', string $name = 'Asha Rao'): string
    {
        return $this->postJson('/api/v1/recruitment/candidates', ['name' => $name, 'email' => $email, 'phone' => '+91 90000 00000', 'source' => 'Referral', 'privacy_notice_version' => '2026.1', 'consent_reference' => 'Consent ref 001',
            'consented_at' => '2026-10-01T10:00:00+05:30', 'retention_until' => '2027-04-01'])->assertCreated()->json('uuid');
    }

    private function applicationAtOffer(string $req, string $candidate): string
    {
        $app = $this->postJson('/api/v1/recruitment/applications', ['candidate_uuid' => $candidate, 'requisition_uuid' => $req, 'source' => 'Referral'])->assertCreated()->json('uuid');
        $from = 'applied';
        foreach (['screening', 'assessment', 'interview', 'offer'] as $stage) {
            $this->postJson('/api/v1/recruitment/applications/'.$app.'/transition', ['stage' => $stage, 'expected_stage' => $from, 'status' => 'active', 'reason' => 'Progressing on job-related criteria', 'idempotency_key' => (string) Str::uuid()])->assertOk();
            $from = $stage;
        }

        return $app;
    }

    private function acceptedOffer(string $app): string
    {
        $offer = $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Software engineer', 'start_date' => '2026-11-02', 'compensation_reference' => 'Band E3, structure reference STR-2', 'currency' => 'inr'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/submit')->assertOk();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/approve')->assertForbidden(); // maker cannot check
        $this->signIn($this->checker)->postJson('/api/v1/recruitment/offers/'.$offer.'/approve')->assertOk()->assertJsonPath('status', 'approved');
        $this->signIn($this->admin)->postJson('/api/v1/recruitment/offers/'.$offer.'/send')->assertOk();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/accept')->assertOk()->assertJsonPath('status', 'accepted');

        return $offer;
    }

    private function handoverPayload(array $o = []): array
    {
        return $o + ['assignment' => ['department_id' => $this->org['department'][0], 'designation_id' => $this->org['designation'][0], 'employee_type_id' => $this->org['employee_type'][0], 'location_id' => $this->org['location'][0]]];
    }

    public function test_offer_to_employee_handover_end_to_end(): void
    {
        $req = $this->requisition();
        $app = $this->applicationAtOffer($req, $this->candidate());
        $offer = $this->acceptedOffer($app);
        $this->context($this->admin->tenant_id);
        $before = Employee::count();

        $res = $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload())->assertCreated()->assertJsonStructure(['employee_uuid', 'employee_number', 'status']);
        $this->context($this->admin->tenant_id);
        $this->assertSame($before + 1, Employee::count());
        $retry = $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload())->assertCreated();
        $this->assertSame($res->json('employee_uuid'), $retry->json('employee_uuid'));
        $this->context($this->admin->tenant_id);
        $this->assertSame($before + 1, Employee::count()); // no duplicate employment

        $employee = Employee::where('uuid', $res->json('employee_uuid'))->firstOrFail();
        $this->assertSame('preboarding', $employee->status);
        $this->assertSame('2026-11-02', $employee->joined_on->toDateString());
        $this->assertSame('Asha Rao', $employee->person->legal_name);
        $this->assertSame($this->org['legal_entity'][0], (int) DB::table('employee_assignments')->where('employee_id', $employee->id)->value('legal_entity_id'));
        $this->assertDatabaseHas('recruitment_applications', ['uuid' => $app, 'status' => 'hired', 'employee_id' => $employee->id]);
        $this->assertDatabaseHas('recruitment_requisitions', ['uuid' => $req, 'status' => 'filled', 'filled_count' => 1]);

        $sections = app(RecruitmentEmployee360Provider::class)->sections($this->admin, $employee, []);
        $this->assertSame('Recruitment source', $sections[0]['title']);
        $this->assertSame('Referral', $sections[0]['items'][0]['value']);
        $this->assertSame([], app(RecruitmentEmployee360Provider::class)->sections($this->userWithRole('Team member', 'm@alpha.test'), $employee, []));

        $analytics = $this->getJson('/api/v1/recruitment/analytics')->assertOk()->json();
        $this->assertSame(1, $analytics['requisitions']['filled']);
        $this->assertSame(1, $analytics['time_to_fill']['filled_requisitions']);
        $this->assertSame(1, $analytics['pipeline']['by_status']['hired']);
        $this->assertSame('100.0', $analytics['offers']['acceptance_rate']);
    }

    public function test_handover_requires_accepted_offer_and_free_headcount(): void
    {
        $req = $this->requisition(1);
        $first = $this->applicationAtOffer($req, $this->candidate('one@example.test', 'Ravi One'));
        $second = $this->applicationAtOffer($req, $this->candidate('two@example.test', 'Meena Two'));
        $o1 = $this->acceptedOffer($first);
        $o2 = $this->acceptedOffer($second);
        $draft = $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $this->applicationAtOffer($this->requisition(), $this->candidate('three@example.test', 'Sam Three')), 'job_title' => 'Engineer', 'start_date' => '2026-11-02'])->json('uuid');
        $this->postJson('/api/v1/recruitment/offers/'.$draft.'/handover', $this->handoverPayload())->assertConflict();
        $this->postJson('/api/v1/recruitment/offers/'.$o1.'/handover', $this->handoverPayload())->assertCreated();
        $this->postJson('/api/v1/recruitment/offers/'.$o2.'/handover', $this->handoverPayload())->assertConflict(); // single opening already filled
    }

    public function test_handover_warns_about_existing_person_and_needs_confirmation(): void
    {
        $this->hire('Asha Rao', ['personal_email' => 'asha@example.test']);
        $offer = $this->acceptedOffer($this->applicationAtOffer($this->requisition(), $this->candidate()));
        $this->context($this->admin->tenant_id);
        $count = Employee::count();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload())->assertConflict()->assertJsonPath('code', 'possible_duplicate');
        $this->context($this->admin->tenant_id);
        $this->assertSame($count, Employee::count());
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload(['confirm_duplicates' => true]))->assertCreated();
        $this->context($this->admin->tenant_id);
        $this->assertSame($count + 1, Employee::count());
    }

    public function test_rehire_handover_preserves_person_and_requires_identity_and_eligibility_review(): void
    {
        $former = $this->hire('Asha Rao', ['personal_email' => 'asha@example.test']);
        $offer = $this->acceptedOffer($this->applicationAtOffer($this->requisition(), $this->candidate()));
        $path = '/api/v1/recruitment/offers/'.$offer.'/handover';
        $body = $this->handoverPayload(['previous_employee_uuid' => $former->uuid]);
        $this->postJson($path, $body)->assertUnprocessable()->assertJsonValidationErrors('rehire_identity_confirmed');
        $body['rehire_identity_confirmed'] = true;
        $this->postJson($path, $body)->assertUnprocessable(); // current employment cannot be rehired
        $this->postJson('/api/v1/employees/'.$former->uuid.'/separation', ['type' => 'contract_end', 'last_working_date' => '2026-10-07', 'rehire_eligible' => false])->assertCreated();
        $this->postJson('/api/v1/employees/'.$former->uuid.'/separation/complete')->assertOk();
        $this->postJson($path, $body)->assertUnprocessable()->assertJsonValidationErrors('override_reason');
        $this->context($this->admin->tenant_id);
        $people = DB::table('people')->where('tenant_id', $this->admin->tenant_id)->count();
        $body['override_reason'] = 'Eligibility reviewed for the new position';
        $result = $this->postJson($path, $body)->assertCreated()->json('employee_uuid');
        $this->postJson($path, $body)->assertCreated()->assertJsonPath('employee_uuid', $result);
        $this->context($this->admin->tenant_id);
        $new = Employee::where('uuid', $result)->firstOrFail();
        $this->assertSame($former->person_id, $new->person_id);
        $this->assertSame($former->id, $new->rehire_of_id);
        $this->assertSame(2, $new->employment_sequence);
        $this->assertSame($people, DB::table('people')->where('tenant_id', $this->admin->tenant_id)->count());
        $this->assertDatabaseHas('recruitment_handovers', ['employee_id' => $new->id, 'person_id' => $former->person_id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rehired']);
    }

    public function test_rehire_handover_checks_permission_and_employee_scope(): void
    {
        $former = $this->hire('Asha Rao', ['personal_email' => 'asha@example.test']);
        $offer = $this->acceptedOffer($this->applicationAtOffer($this->requisition(), $this->candidate()));
        $this->context($this->admin->tenant_id);
        $role = Role::create(['name' => 'Restricted hiring converter', 'data_scope' => 'tenant', 'system' => false]);
        $role->permissions()->sync(Permission::whereIn('key', ['recruitment.handover', 'people.manage'])->pluck('id'));
        $this->checker->roles()->sync([$role->id]);
        $body = $this->handoverPayload(['previous_employee_uuid' => $former->uuid, 'rehire_identity_confirmed' => true]);
        $path = '/api/v1/recruitment/offers/'.$offer.'/handover';
        $this->signIn($this->checker)->postJson($path, $body)->assertForbidden();
        $this->context($this->admin->tenant_id);
        $scoped = Role::create(['name' => 'Scoped rehire', 'data_scope' => 'self', 'system' => false]);
        $scoped->permissions()->sync(Permission::where('key', 'people.rehire')->pluck('id'));
        $this->checker->roles()->attach($scoped->id);
        $this->signIn($this->checker)->postJson($path, $body)->assertNotFound();
        $this->signIn($this->admin)->postJson($path, array_replace($body, ['previous_employee_uuid' => (string) Str::uuid()]))->assertNotFound();
    }

    public function test_offer_versioning_and_state_rules(): void
    {
        $app = $this->applicationAtOffer($this->requisition(), $this->candidate());
        $v1 = $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Engineer', 'start_date' => '2026-11-02'])->assertCreated()->assertJsonPath('version', 1)->json('uuid');
        $this->postJson('/api/v1/recruitment/offers/'.$v1.'/send')->assertConflict(); // not approved
        $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Engineer II', 'start_date' => '2026-11-09'])->assertCreated()->assertJsonPath('version', 2);
        $this->assertDatabaseHas('recruitment_offers', ['uuid' => $v1, 'status' => 'superseded']);
        $this->assertNotSame('Band E3', DB::table('recruitment_offers')->value('compensation_reference'));
        $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'X', 'start_date' => '2026-11-09', 'benchmark_employee_uuid' => (string) Str::uuid()])->assertStatus(404);
    }

    public function test_interview_scorecards_are_private_to_each_panelist(): void
    {
        $app = $this->applicationAtOffer($this->requisition(), $this->candidate());
        $this->context($this->admin->tenant_id);
        $view = Permission::where('key', 'recruitment.view')->firstOrFail();
        $role = Role::create(['name' => 'Panelist', 'description' => 'Interview panel', 'data_scope' => 'self', 'system' => false]);
        $role->permissions()->sync([$view->id]);
        $p1 = new User(['name' => 'Panel One', 'email' => 'p1@alpha.test', 'password' => 'StrongPassword123']);
        $p1->tenant_id = $this->admin->tenant_id;
        $p1->save();
        $p1->roles()->attach($role->id);
        $p2 = new User(['name' => 'Panel Two', 'email' => 'p2@alpha.test', 'password' => 'StrongPassword123']);
        $p2->tenant_id = $this->admin->tenant_id;
        $p2->save();
        $p2->roles()->attach($role->id);

        $this->signIn($p1)->getJson('/api/v1/recruitment/applications')->assertOk()->assertJsonCount(0, 'data'); // not on the panel yet
        $this->signIn($this->admin);
        $interview = $this->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $app, 'round' => 'Technical', 'starts_at' => '2026-10-07T09:00', 'ends_at' => '2026-10-07T10:00', 'timezone' => 'Asia/Kolkata', 'panelist_ids' => [$p1->id, $p2->id]])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $app, 'round' => 'Clash', 'starts_at' => '2026-10-07T09:30', 'ends_at' => '2026-10-07T10:30', 'timezone' => 'Asia/Kolkata', 'panelist_ids' => [$p1->id]])->assertUnprocessable();
        $this->assertDatabaseHas('work_items', ['assignee_id' => $p1->id, 'source_module' => 'recruitment', 'status' => 'open']);

        $this->signIn($p1)->getJson('/api/v1/recruitment/applications')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/recruitment/interviews/'.$interview.'/scorecard', ['rating' => 4, 'recommendation' => 'yes', 'strengths' => 'Clear reasoning', 'concerns' => 'Needs depth in testing'])->assertCreated();
        $this->postJson('/api/v1/recruitment/interviews/'.$interview.'/scorecard', ['rating' => 4, 'recommendation' => 'yes'])->assertConflict();
        $this->assertNotSame('Clear reasoning', DB::table('recruitment_scorecards')->value('strengths')); // encrypted at rest
        $this->assertDatabaseHas('work_items', ['assignee_id' => $p1->id, 'source_module' => 'recruitment', 'status' => 'completed']);

        $this->signIn($p2)->getJson('/api/v1/recruitment/interviews/'.$interview.'/scorecards')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/recruitment/interviews/'.$interview.'/scorecard', ['rating' => 2, 'recommendation' => 'no'])->assertCreated();
        $this->getJson('/api/v1/recruitment/interviews/'.$interview.'/scorecards')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mine', true);
        $this->signIn($p1)->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $app, 'round' => 'X', 'starts_at' => '2026-10-08T09:00', 'ends_at' => '2026-10-08T10:00', 'timezone' => 'UTC', 'panelist_ids' => [$p1->id]])->assertForbidden();
        $this->signIn($this->admin)->getJson('/api/v1/recruitment/interviews/'.$interview.'/scorecards')->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('recruitment_interviews', ['uuid' => $interview, 'status' => 'completed']);
    }

    public function test_authorization_and_tenant_isolation(): void
    {
        $offer = $this->acceptedOffer($this->applicationAtOffer($this->requisition(), $this->candidate()));
        $this->context($this->admin->tenant_id);
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $this->signIn($member);
        foreach (['offers', 'analytics', 'interviews', 'postings'] as $path) {
            $this->getJson('/api/v1/recruitment/'.$path)->assertForbidden();
        }
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload())->assertForbidden();

        $other = $this->bootTenant('beta');
        $this->signIn($other);
        $this->getJson('/api/v1/recruitment/offers')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/handover', $this->handoverPayload(['assignment' => ['department_id' => $this->org['department'][0], 'designation_id' => $this->org['designation'][0], 'employee_type_id' => $this->org['employee_type'][0], 'location_id' => $this->org['location'][0]]]))->assertNotFound();
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/withdraw')->assertNotFound();
        $this->assertSame(0, $this->getJson('/api/v1/recruitment/analytics')->json('requisitions.filled'));
    }

    public function test_interview_rescheduling_preserves_panel_tasks_and_checks_conflicts_and_stale_edits(): void
    {
        $app = $this->applicationAtOffer($this->requisition(), $this->candidate());
        $schedule = ['application_uuid' => $app, 'round' => 'Technical', 'starts_at' => '2026-10-08T10:00', 'ends_at' => '2026-10-08T11:00', 'timezone' => 'Asia/Kolkata', 'panelist_ids' => [$this->checker->id]];
        $id = $this->postJson('/api/v1/recruitment/interviews', $schedule)->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/interviews', array_replace($schedule, ['starts_at' => '2026-10-09T10:00', 'ends_at' => '2026-10-09T11:00']))->assertCreated();
        $path = '/api/v1/recruitment/interviews/'.$id.'/reschedule';
        $move = ['starts_at' => '2026-10-09T10:30', 'ends_at' => '2026-10-09T11:30', 'timezone' => 'Asia/Kolkata', 'expected_starts_at' => '2026-10-08T04:30:00Z', 'reason' => 'Candidate availability changed'];
        $this->postJson($path, $move)->assertUnprocessable();
        $move['starts_at'] = '2026-10-09T14:00';
        $move['ends_at'] = '2026-10-09T15:00';
        $this->postJson($path, $move)->assertOk()->assertJsonPath('starts_at', '2026-10-09T08:30:00+00:00');
        $this->postJson($path, $move)->assertConflict();
        $this->assertSame(1, DB::table('work_items')->where('source_entity', 'scorecard:'.$id.':'.$this->checker->id)->where('status', 'open')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'interview.rescheduled', 'resource' => $id]);
        $this->context($this->admin->tenant_id);
        $this->signIn($this->userWithRole('Team member', 'member@alpha.test'))->postJson($path, $move)->assertForbidden();
        $other = app(WorkspaceProvisioner::class)->create('Other tenant', 'other-interviews', 'admin@other-interviews.test', 'StrongPassword123');
        $this->signIn($other)->postJson($path, $move)->assertNotFound();
        $this->signIn($this->admin)->postJson('/api/v1/recruitment/interviews', array_replace($schedule, ['panelist_ids' => [$other->id]]))->assertUnprocessable();
        $this->postJson('/api/v1/recruitment/interviews/'.$id.'/cancel')->assertOk();
        $this->postJson($path, array_replace($move, ['expected_starts_at' => '2026-10-09T08:30:00Z']))->assertConflict();
        $this->assertDatabaseHas('work_items', ['source_entity' => 'scorecard:'.$id.':'.$this->checker->id, 'status' => 'completed']);
    }

    public function test_recruitment_notification_holders_do_not_cross_tenants(): void
    {
        $other = app(WorkspaceProvisioner::class)->create('Other tenant', 'other-notices', 'admin@other-notices.test', 'StrongPassword123');
        $this->signIn($this->admin);
        $this->acceptedOffer($this->applicationAtOffer($this->requisition(), $this->candidate()));
        $this->assertNotContains($other->id, RecruitmentNotify::holders('recruitment.handover')->pluck('id')->all());
    }

    public function test_candidate_duplicates_masking_and_anonymization(): void
    {
        $uuid = $this->candidate();
        $this->postJson('/api/v1/recruitment/candidates', ['name' => 'X', 'email' => 'ASHA@Example.test', 'source' => 's', 'privacy_notice_version' => 'v', 'consent_reference' => 'c', 'consented_at' => '2026-10-01T00:00:00Z', 'retention_until' => '2027-04-01'])->assertConflict();
        $this->postJson('/api/v1/recruitment/candidates', ['name' => 'Asha Rao', 'email' => 'other@example.test', 'source' => 's', 'privacy_notice_version' => 'v', 'consent_reference' => 'c', 'consented_at' => '2026-10-01T00:00:00Z', 'retention_until' => '2027-04-01'])
            ->assertCreated()->assertJsonPath('possible_duplicates.0', $uuid);
        $this->assertNotSame('+91 90000 00000', DB::table('recruitment_candidates')->value('phone'));

        $this->context($this->admin->tenant_id);
        $viewer = $this->userWithRole('HR administrator', 'hr2@alpha.test');
        $this->getJson('/api/v1/recruitment/candidates')->assertOk()->assertJsonPath('data.1.email', 'asha@example.test');
        $this->postJson('/api/v1/recruitment/candidates/'.$uuid.'/anonymize', [])->assertConflict(); // still inside retention
        $this->postJson('/api/v1/recruitment/candidates/'.$uuid.'/anonymize', ['erasure_request' => true])->assertOk();
        $this->assertDatabaseHas('recruitment_candidates', ['uuid' => $uuid, 'name' => 'Anonymized applicant']);
        $this->assertNotNull($viewer);
    }

    public function test_housekeeping_expires_local_records_and_sends_reminders_once(): void
    {
        $req = $this->requisition(3);
        $app = $this->applicationAtOffer($req, $this->candidate());
        $offer = $this->postJson('/api/v1/recruitment/offers', ['application_uuid' => $app, 'job_title' => 'Engineer', 'start_date' => '2026-11-02', 'expires_on' => '2026-10-08'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/offers/'.$offer.'/submit')->assertOk();
        $posting = $this->postJson('/api/v1/recruitment/postings', ['requisition_uuid' => $req, 'title' => 'Engineer', 'description' => 'Join us', 'channel' => 'internal', 'closes_on' => '2026-10-08'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/postings/'.$posting.'/publish')->assertOk();
        $interview = $this->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $app, 'round' => 'Technical', 'starts_at' => '2026-10-08T10:00', 'ends_at' => '2026-10-08T11:00', 'timezone' => 'UTC', 'panelist_ids' => [$this->checker->id]])->assertCreated()->json('uuid');
        $acceptedApp = $this->applicationAtOffer($req, $this->candidate('second@example.test'));
        $accepted = $this->acceptedOffer($acceptedApp);
        DB::table('recruitment_offers')->where('uuid', $accepted)->update(['expires_on' => '2026-10-08']);
        $this->travelTo(CarbonImmutable::parse('2026-10-08T12:00:00Z'));
        $this->artisan('recruitment:housekeeping', ['--tenant' => $this->admin->tenant_id])->assertSuccessful();
        $this->assertDatabaseHas('recruitment_offers', ['uuid' => $offer, 'status' => 'pending_approval']);
        $this->travelTo(CarbonImmutable::parse('2026-11-08T12:00:00Z'));
        $this->artisan('recruitment:housekeeping', ['--tenant' => $this->admin->tenant_id])->assertSuccessful();
        $this->assertDatabaseHas('recruitment_offers', ['uuid' => $offer, 'status' => 'expired']);
        $this->assertDatabaseHas('recruitment_offers', ['uuid' => $accepted, 'status' => 'accepted']);
        $this->assertDatabaseHas('recruitment_postings', ['uuid' => $posting, 'status' => 'closed']);
        $this->assertDatabaseHas('work_items', ['source_entity' => 'offer:'.$offer, 'status' => 'completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.stage_reminded', 'resource' => $app]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'interview.feedback_reminded', 'resource' => $interview]);
        $count = DB::table('audit_logs')->whereIn('action', ['application.stage_reminded', 'interview.feedback_reminded', 'offer.expired', 'posting.expired'])->count();
        $this->artisan('recruitment:housekeeping', ['--tenant' => $this->admin->tenant_id])->assertSuccessful();
        $this->assertSame($count, DB::table('audit_logs')->whereIn('action', ['application.stage_reminded', 'interview.feedback_reminded', 'offer.expired', 'posting.expired'])->count());
    }

    public function test_housekeeping_skips_closed_and_privacy_restricted_applications_and_disabled_tenants(): void
    {
        $req = $this->requisition();
        $candidate = $this->candidate();
        $app = $this->applicationAtOffer($req, $candidate);
        $interview = $this->postJson('/api/v1/recruitment/interviews', ['application_uuid' => $app, 'round' => 'Technical', 'starts_at' => '2026-10-08T10:00', 'ends_at' => '2026-10-08T11:00', 'timezone' => 'UTC', 'panelist_ids' => [$this->checker->id]])->assertCreated()->json('uuid');
        $this->travelTo(CarbonImmutable::parse('2026-11-08T12:00:00Z'));
        $this->context($this->admin->tenant_id);
        $housekeeping = app(RecruitmentHousekeeping::class);
        DB::table('recruitment_applications')->where('uuid', $app)->update(['status' => 'withdrawn']);
        $this->assertSame(0, array_sum($housekeeping->run()));
        DB::table('recruitment_applications')->where('uuid', $app)->update(['status' => 'active']);
        DB::table('recruitment_candidates')->where('uuid', $candidate)->update(['retention_until' => '2026-11-01']);
        $this->assertSame(0, array_sum($housekeeping->run()));
        DB::table('recruitment_candidates')->where('uuid', $candidate)->update(['retention_until' => '2027-04-01', 'anonymized_at' => now()]);
        $this->assertSame(0, array_sum($housekeeping->run()));
        DB::table('recruitment_candidates')->where('uuid', $candidate)->update(['anonymized_at' => null]);
        DB::table('users')->whereIn('id', [$this->admin->id, $this->checker->id])->update(['active' => false]);
        $this->assertSame(0, array_sum($housekeeping->run()));
        DB::table('users')->whereIn('id', [$this->admin->id, $this->checker->id])->update(['active' => true]);
        $tenant = Tenant::findOrFail($this->admin->tenant_id);
        $settings = $tenant->settings;
        $settings['modules'] = array_values(array_diff($settings['modules'], ['recruitment']));
        $tenant->update(['settings' => $settings]);
        $this->artisan('recruitment:housekeeping', ['--tenant' => $tenant->id])->assertSuccessful();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'interview.feedback_reminded', 'resource' => $interview]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'application.stage_reminded', 'resource' => $app]);
    }

    public function test_postings_requisition_budget_and_registries(): void
    {
        $req = $this->requisition(2);
        $this->assertDatabaseHas('recruitment_requisitions', ['uuid' => $req, 'budget_amount' => '1200000.00', 'budget_currency' => 'INR']);
        $posting = $this->postJson('/api/v1/recruitment/postings', ['requisition_uuid' => $req, 'title' => 'Software engineer', 'description' => 'Join us.', 'channel' => 'internal'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/recruitment/postings/'.$posting.'/publish')->assertOk()->assertJsonPath('status', 'published');
        $this->postJson('/api/v1/recruitment/postings/'.$posting.'/publish')->assertConflict();
        $this->postJson('/api/v1/recruitment/requisitions/'.$req.'/close')->assertOk();
        $this->assertDatabaseHas('recruitment_postings', ['uuid' => $posting, 'status' => 'closed']);

        $pending = $this->postJson('/api/v1/recruitment/requisitions', ['job_uuid' => $this->postJson('/api/v1/recruitment/jobs', ['code' => 'OPS', 'title' => 'Ops', 'description' => 'Ops'])->json('uuid'), 'legal_entity_id' => $this->org['legal_entity'][0],
            'recruiter_id' => $this->admin->id, 'hiring_manager_id' => $this->admin->id, 'openings' => 1, 'target_date' => '2026-12-01', 'kind' => 'new', 'reason' => 'r', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('uuid');
        $this->signIn($this->checker);
        $titles = collect(app(RecruitmentInsightProvider::class)->insights($this->checker))->pluck('key')->all();
        $this->assertContains('recruitment.requisitions_pending', $titles);
        $this->assertNotNull($pending);
        $this->assertCount(4, RecruitmentQualityRules::all());
        $this->assertSame([], iterator_to_array(RecruitmentQualityRules::all()[2]->scan($this->admin->tenant_id), false));
    }
}
