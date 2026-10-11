<?php

namespace Tests\Feature\People;

use App\Domain\Access\PeopleSettings;
use App\Domain\People\Models\EmployeeCertification;
use App\Domain\People\Models\EmployeeContact;
use App\Domain\People\Models\EmployeeExperience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SectionsFixtures;
use Tests\TestCase;

class SectionsTest extends TestCase
{
    use RefreshDatabase, SectionsFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSections();
    }

    public function test_contacts_crud_with_one_primary_per_kind(): void
    {
        $this->signIn($this->admin);
        $a = $this->postJson($this->path('contacts'), ['kind' => 'email', 'label' => 'personal', 'value' => 'A@Example.com'])->assertCreated()->assertJsonPath('is_primary', true)->assertJsonPath('value', 'a@example.com');
        $this->assertArrayNotHasKey('id', $a->json());
        $b = $this->postJson($this->path('contacts'), ['kind' => 'email', 'label' => 'home', 'value' => 'b@example.com', 'is_primary' => true])->assertCreated()->assertJsonPath('is_primary', true);
        $this->postJson($this->path('contacts'), ['kind' => 'phone', 'label' => 'mobile', 'value' => '+91 98765 43210', 'country_code' => '+91'])->assertCreated()->assertJsonPath('is_primary', true);

        $list = $this->getJson($this->path('contacts'))->assertOk();
        $this->assertSame(['b@example.com'], collect($list->json('data'))->where('kind', 'email')->where('is_primary', true)->pluck('value')->all());

        $this->postJson($this->path('contacts'), ['kind' => 'email', 'label' => 'home', 'value' => 'bad'])->assertStatus(422);
        $this->postJson($this->path('contacts'), ['kind' => 'phone', 'label' => 'home', 'value' => 'abc'])->assertStatus(422);
        $this->postJson($this->path('contacts'), ['kind' => 'email', 'label' => 'home', 'value' => 'b@example.com'])->assertStatus(422);
        $this->patchJson($this->path('contacts/'.$b->json('uuid')), ['is_primary' => false])->assertStatus(422);

        // End-dating the primary promotes the other current contact.
        $this->patchJson($this->path('contacts/'.$b->json('uuid')), ['effective_to' => '2026-10-01'])->assertOk()->assertJsonPath('is_primary', false)->assertJsonPath('is_current', false);
        $this->inTenant();
        $this->assertSame(1, EmployeeContact::where('kind', 'email')->where('is_primary', true)->count());
        $this->assertTrue((bool) EmployeeContact::where('uuid', $a->json('uuid'))->value('is_primary'));

        $this->signIn($this->admin);
        $this->deleteJson($this->path('contacts/'.$a->json('uuid')))->assertOk();
        $this->assertAudited('contact_added');
        $this->assertAudited('contact_removed');
        $this->assertEvent('ContactAdded');
        $this->assertStringNotContainsString('example.com', $this->assertEvent('ContactUpdated')->summary);
    }

    public function test_emergency_contacts_primary_and_no_sensitive_values_in_audit_or_events(): void
    {
        $this->signIn($this->admin);
        $one = $this->postJson($this->path('emergency-contacts'), ['name' => 'Ravi Rao', 'relationship' => 'Brother', 'phone' => '+91 90000 11111'])->assertCreated()->assertJsonPath('is_primary', true);
        $two = $this->postJson($this->path('emergency-contacts'), ['name' => 'Mira Rao', 'relationship' => 'Mother', 'phone' => '+91 90000 22222', 'is_primary' => true])->assertCreated();
        $list = $this->getJson($this->path('emergency-contacts'))->assertOk();
        $this->assertCount(1, collect($list->json('data'))->where('is_primary', true));
        $this->patchJson($this->path('emergency-contacts/'.$two->json('uuid')), ['phone' => '+91 90000 33333'])->assertOk();
        $this->postJson($this->path('emergency-contacts'), ['name' => '', 'relationship' => 'x', 'phone' => '1'])->assertStatus(422);
        $this->deleteJson($this->path('emergency-contacts/'.$two->json('uuid')))->assertOk();
        $this->inTenant();
        $this->assertSame(1, DB::table('employee_emergency_contacts')->where('is_primary', true)->count());

        $event = $this->assertEvent('EmergencyContactAdded');
        $this->assertSame('Emergency contact added.', $event->summary);
        $audit = json_encode($this->assertAudited('emergency_contact_updated')->context);
        $this->assertStringNotContainsString('90000', $audit);
        $this->assertSame($one->json('uuid'), $this->getJson($this->path('emergency-contacts'))->json('data.0.uuid') ?? $one->json('uuid'));
    }

    public function test_dependents_are_end_dated_and_validated(): void
    {
        $this->signIn($this->admin);
        $d = $this->postJson($this->path('dependents'), ['name' => 'Kid Rao', 'relationship' => 'Child', 'date_of_birth' => '2018-03-04', 'effective_from' => '2020-01-01', 'benefit_eligibility' => ['medical' => true]])->assertCreated()
            ->assertJsonPath('status', 'active')->assertJsonPath('benefit_eligibility.medical', true);
        $this->postJson($this->path('dependents'), ['name' => 'X', 'relationship' => 'Child', 'effective_from' => '2020-01-01', 'effective_to' => '2019-01-01'])->assertStatus(422);
        $this->postJson($this->path('dependents'), ['name' => 'X', 'relationship' => 'Child', 'date_of_birth' => '2030-01-01'])->assertStatus(422);
        $this->patchJson($this->path('dependents/'.$d->json('uuid')), ['effective_to' => '2026-09-30'])->assertOk()->assertJsonPath('status', 'inactive');
        $this->patchJson($this->path('dependents/'.$d->json('uuid')), ['effective_to' => '2019-01-01'])->assertStatus(422);
        $this->assertStringNotContainsString('Kid', $this->assertEvent('DependentAdded')->summary);
        $this->deleteJson($this->path('dependents/'.$d->json('uuid')))->assertOk();
        $this->assertAudited('dependent_removed');
    }

    public function test_education_crud_verification_and_reset_on_edit(): void
    {
        $this->signIn($this->admin);
        $e = $this->postJson($this->path('education'), ['institution' => 'MIT', 'qualification' => 'BSc', 'start_date' => '2010-06-01', 'end_date' => '2014-05-30', 'country' => 'us'])->assertCreated()
            ->assertJsonPath('country', 'US')->assertJsonPath('verification.status', 'unverified');
        $this->postJson($this->path('education'), ['institution' => 'X', 'qualification' => 'Y', 'start_date' => '2014-01-01', 'end_date' => '2013-01-01'])->assertStatus(422);
        $id = $e->json('uuid');

        $this->as($this->emp)->postJson($this->path("education/$id/verify"), ['status' => 'verified'])->assertForbidden();
        $this->as($this->hrFull)->postJson($this->path("education/$id/verify"), ['status' => 'verified', 'note' => 'Checked transcript'])->assertOk()
            ->assertJsonPath('verification.status', 'verified')->assertJsonPath('verification.verified_by', 'Hera Full')->assertJsonPath('verification.note', 'Checked transcript');
        $this->assertEvent('EducationVerified');
        $this->patchJson($this->path("education/$id"), ['grade' => 'A'])->assertOk()->assertJsonPath('verification.status', 'unverified');
        $this->patchJson($this->path("education/$id/verify"), [])->assertStatus(405);
        $this->postJson($this->path("education/$id/verify"), ['status' => 'maybe'])->assertStatus(422);

        $this->as($this->hrFull)->deleteJson($this->path("education/$id"))->assertOk();
        $this->assertAudited('education_added');
        $this->assertEvent('EducationRemoved');
    }

    public function test_documents_must_belong_to_the_person(): void
    {
        $typeId = DB::table('document_types')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'code' => 'degree', 'name' => 'Degree', 'category' => 'education', 'created_at' => now(), 'updated_at' => now()]);
        $uuid = (string) \Illuminate\Support\Str::uuid();
        DB::table('employee_documents')->insert(['uuid' => $uuid, 'tenant_id' => $this->admin->tenant_id, 'person_id' => $this->emp->person_id, 'document_type_id' => $typeId, 'title' => 'Degree', 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($this->admin);
        $this->postJson($this->path('education'), ['institution' => 'MIT', 'qualification' => 'BSc', 'document_uuid' => $uuid])->assertCreated()->assertJsonPath('document_uuid', $uuid);
        $this->postJson($this->path('education'), ['institution' => 'MIT', 'qualification' => 'BSc', 'document_uuid' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(422);
    }

    public function test_experience_merges_overlaps_and_protects_last_salary(): void
    {
        $this->signIn($this->admin);
        $rows = [
            ['A', '2015-01-01', '2017-12-31'], ['B', '2017-06-01', '2019-12-31'], ['C', '2020-01-01', '2020-06-30'], ['D', '2021-03-01', '2021-05-31'],
        ];
        foreach ($rows as [$employer, $from, $to]) {
            $res = $this->postJson($this->path('experience'), ['employer' => $employer, 'job_title' => 'Dev', 'start_date' => $from, 'end_date' => $to, 'last_salary' => 850000, 'last_salary_currency' => 'inr', 'reason_for_leaving' => 'Growth'])->assertCreated();
        }
        $this->assertEquals(850000, $res->json('last_salary'));
        $this->assertSame('INR', $res->json('last_salary_currency'));
        $list = $this->getJson($this->path('experience'))->assertOk();
        $this->assertSame(['years' => 5, 'months' => 9], $list->json('total_experience'));
        $this->assertSame('full', $list->json('viewer.salary'));

        $this->postJson($this->path('experience'), ['employer' => 'Z', 'job_title' => 'Dev', 'start_date' => '2020-01-01', 'end_date' => '2019-01-01'])->assertStatus(422);
        $this->postJson($this->path('experience'), ['employer' => 'Z', 'job_title' => 'Dev', 'start_date' => '2099-01-01'])->assertStatus(422);

        // Stored encrypted.
        $this->inTenant();
        $raw = EmployeeExperience::where('employer', 'A')->first()->getRawOriginal('last_salary');
        $this->assertNotSame('850000', $raw);
        $this->assertStringNotContainsString('850000', $raw);
        $this->assertStringNotContainsString('850000', json_encode($this->assertAudited('experience_added')->context));

        // Self sees it masked, line manager and limited HR do not see it at all.
        $self = $this->as($this->emp)->getJson($this->path('experience'))->assertOk();
        $this->assertSame('masked', $self->json('viewer.salary'));
        $this->assertStringNotContainsString('850000', $self->getContent());
        $this->assertArrayHasKey('last_salary', $self->json('data.0'));
        $mgr = $this->as($this->boss)->getJson($this->path('experience'))->assertOk();
        $this->assertArrayNotHasKey('last_salary', $mgr->json('data.0'));
        $this->assertArrayNotHasKey('reason_for_leaving', $mgr->json('data.0'));
        $hr = $this->as($this->hrLimited)->getJson($this->path('experience'))->assertOk();
        $this->assertArrayNotHasKey('last_salary', $hr->json('data.0'));
        $this->as($this->hrLimited)->patchJson($this->path('experience/'.$hr->json('data.0.uuid')), ['last_salary' => 1])->assertForbidden();
    }

    public function test_skills_catalog_assignment_and_verification(): void
    {
        $this->as($this->hrLimited)->postJson('/api/v1/people/skills', ['name' => 'PHP'])->assertForbidden();
        $this->signIn($this->admin);
        $php = $this->postJson('/api/v1/people/skills', ['name' => 'PHP', 'category' => 'Engineering'])->assertCreated()->json('id');
        $this->postJson('/api/v1/people/skills', ['name' => 'php'])->assertStatus(422);
        $old = $this->postJson('/api/v1/people/skills', ['name' => 'COBOL'])->assertCreated()->json('id');
        $this->patchJson("/api/v1/people/skills/$old", ['active' => false])->assertOk()->assertJsonPath('active', false);
        $this->assertCount(1, $this->getJson('/api/v1/people/skills')->json('data'));
        $this->assertCount(2, $this->getJson('/api/v1/people/skills?include_inactive=1')->json('data'));

        // Self-service (default rule: direct).
        $this->as($this->emp)->postJson($this->path('skills'), ['skill_id' => $php, 'proficiency' => 'advanced', 'years_experience' => 4.5])->assertCreated()->assertJsonPath('skill.name', 'PHP');
        $this->postJson($this->path('skills'), ['skill_id' => $php])->assertStatus(422);
        $this->postJson($this->path('skills'), ['skill_id' => $old])->assertStatus(422);
        $this->patchJson($this->path("skills/$php"), ['proficiency' => 'expert'])->assertOk()->assertJsonPath('proficiency', 'expert');
        $this->postJson($this->path("skills/$php/verify"), ['status' => 'verified'])->assertForbidden();
        $this->as($this->hrFull)->postJson($this->path("skills/$php/verify"), ['status' => 'verified'])->assertOk()->assertJsonPath('verification.status', 'verified');
        $this->as($this->boss)->getJson($this->path('skills'))->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->boss)->postJson($this->path('skills'), ['skill_id' => $php])->assertForbidden();
        $this->as($this->emp)->deleteJson($this->path("skills/$php"))->assertOk();
        $this->assertAudited('skill_added');
        $this->assertEvent('SkillRemoved');
    }

    public function test_certifications_expiry_verification_and_skill_link(): void
    {
        $this->signIn($this->admin);
        $skill = $this->postJson('/api/v1/people/skills', ['name' => 'AWS'])->json('id');
        $c = $this->postJson($this->path('certifications'), ['name' => 'AWS SA', 'issuer' => 'Amazon', 'issue_date' => '2025-01-01', 'expiry_date' => '2026-11-15', 'credential_url' => 'https://example.com/c/1'])->assertCreated()
            ->assertJsonPath('expiry_status', 'expiring_soon');
        $this->postJson($this->path('certifications'), ['name' => 'Old', 'issue_date' => '2020-01-01', 'expiry_date' => '2021-01-01'])->assertCreated()->assertJsonPath('expiry_status', 'expired');
        $this->postJson($this->path('certifications'), ['name' => 'Bad', 'issue_date' => '2020-01-01', 'expiry_date' => '2019-01-01'])->assertStatus(422);
        $this->postJson($this->path('certifications'), ['name' => 'Bad', 'credential_url' => 'ftp://x'])->assertStatus(422);
        $this->postJson($this->path('skills'), ['skill_id' => $skill, 'certification_uuid' => $c->json('uuid')])->assertCreated()->assertJsonPath('certification_name', 'AWS SA');
        $this->postJson($this->path('certifications/'.$c->json('uuid').'/verify'), ['status' => 'rejected', 'note' => 'Expired link'])->assertOk()->assertJsonPath('verification.status', 'rejected');
        $this->deleteJson($this->path('certifications/'.$c->json('uuid')))->assertOk();
        $this->getJson($this->path('skills'))->assertJsonPath('data.0.certification_uuid', null);
        $this->inTenant();
        $this->assertSame(1, EmployeeCertification::count());
    }

    public function test_visibility_matrix_for_manager_hr_self_and_unreachable_viewers(): void
    {
        $this->signIn($this->admin);
        foreach (['emergency-contacts' => ['name' => 'R', 'relationship' => 'B', 'phone' => '+91 90000 11111'], 'education' => ['institution' => 'I', 'qualification' => 'Q']] as $seg => $body) {
            $this->postJson($this->path($seg), $body)->assertCreated();
        }
        // Reachable but hidden section: 403. Public-internal section: 200.
        $this->as($this->boss)->getJson($this->path('emergency-contacts'))->assertForbidden()->assertJsonPath('message', 'You do not have access to this section of the profile.');
        $this->as($this->boss)->getJson($this->path('contacts'))->assertForbidden();
        $this->as($this->boss)->getJson($this->path('certifications'))->assertForbidden();
        $this->as($this->boss)->getJson($this->path('education'))->assertOk();
        $this->as($this->emp)->getJson($this->path('emergency-contacts'))->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->hrLimited)->getJson($this->path('emergency-contacts'))->assertOk();
        // Unreachable subject: 404 for everything.
        foreach (['emergency-contacts', 'education', 'skills', 'notes', 'tags', 'experience'] as $seg) {
            $this->as($this->peer)->getJson($this->path($seg))->assertNotFound();
        }
        $this->as($this->peer)->postJson($this->path('education'), ['institution' => 'I', 'qualification' => 'Q'])->assertNotFound();
    }

    public function test_self_service_direct_request_required_and_forbidden(): void
    {
        $this->as($this->emp)->postJson($this->path('emergency-contacts'), ['name' => 'R', 'relationship' => 'B', 'phone' => '+91 90000 11111'])->assertCreated();
        $this->postJson($this->path('education'), ['institution' => 'I', 'qualification' => 'Q'])->assertCreated();
        // Dependents default to change-request.
        $this->postJson($this->path('dependents'), ['name' => 'K', 'relationship' => 'Child'])->assertStatus(409)
            ->assertJsonPath('code', 'change_request_required')->assertJsonPath('section', 'dependent');
        $this->inTenant();
        PeopleSettings::save(['edit_rules' => ['contact' => 'none', 'emergency' => 'request']]);
        $this->as($this->emp)->postJson($this->path('contacts'), ['kind' => 'email', 'label' => 'home', 'value' => 'a@b.com'])->assertForbidden();
        $this->postJson($this->path('emergency-contacts'), ['name' => 'R', 'relationship' => 'B', 'phone' => '+91 90000 11111'])->assertStatus(409)->assertJsonPath('section', 'emergency');
        // HR is always direct.
        $this->as($this->hrLimited)->postJson($this->path('contacts'), ['kind' => 'email', 'label' => 'home', 'value' => 'a@b.com'])->assertCreated();
        // Nothing was written by the rejected requests.
        $this->inTenant();
        $this->assertSame(0, DB::table('employee_dependents')->count());
    }

    public function test_tenant_isolation(): void
    {
        $this->signIn($this->admin);
        $edu = $this->postJson($this->path('education'), ['institution' => 'I', 'qualification' => 'Q'])->assertCreated()->json('uuid');
        $this->postJson('/api/v1/people/skills', ['name' => 'PHP'])->assertCreated();
        $beta = $this->secondTenant();
        $this->context($beta->tenant_id);
        $this->buildOrganization();
        $bea = $this->hire('Bea Beta');
        $this->signIn($beta);
        $this->getJson($this->path('education'))->assertNotFound();
        $this->postJson($this->path('education'), ['institution' => 'I', 'qualification' => 'Q'])->assertNotFound();
        $this->getJson($this->path('education', $bea))->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($this->path("education/$edu", $bea), ['grade' => 'A'])->assertNotFound();
        $this->deleteJson($this->path("education/$edu", $bea))->assertNotFound();
        $this->assertCount(0, $this->getJson('/api/v1/people/skills')->json('data'));
        $this->assertSame(1, DB::table('employee_education')->count());
    }
}
