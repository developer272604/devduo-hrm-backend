<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Recruitment\Models\Resume;
use App\Domain\Recruitment\RecruitmentHousekeeping;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class ResumeTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('hrm_private');
        $this->bootTenant();
        $this->signIn($this->admin);
    }

    private function candidate(string $email = 'asha@example.test'): string
    {
        return $this->postJson('/api/v1/recruitment/candidates', ['name' => 'Asha Rao', 'email' => $email, 'phone' => '+91 90000 00000', 'source' => 'Referral', 'privacy_notice_version' => '2026.1',
            'consent_reference' => 'Consent ref 001', 'consented_at' => '2026-10-01T10:00:00+05:30', 'retention_until' => '2027-04-01'])->assertCreated()->json('uuid');
    }

    private function pdf(string $name = 'cv.pdf', string $body = 'one'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\n% $body\ntrailer<<>>\n%%EOF\n");
    }

    private function docx(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'dx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('word/document.xml', '<w:document/>');
        $zip->close();

        return new UploadedFile($path, 'cv.docx', null, null, true);
    }

    private function applyTo(string $candidate): void
    {
        $this->context($this->admin->tenant_id);
        $checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test');
        $job = $this->postJson('/api/v1/recruitment/jobs', ['code' => 'ENG1', 'title' => 'Engineer', 'description' => 'Build.'])->assertCreated()->json('uuid');
        $req = $this->postJson('/api/v1/recruitment/requisitions', ['job_uuid' => $job, 'legal_entity_id' => $this->org['legal_entity'][0], 'department_id' => $this->org['department'][0], 'recruiter_id' => $this->admin->id,
            'hiring_manager_id' => $this->admin->id, 'openings' => 1, 'target_date' => '2026-12-01', 'kind' => 'new', 'reason' => 'Plan', 'budget_amount' => '1000', 'budget_currency' => 'inr', 'idempotency_key' => (string) \Illuminate\Support\Str::uuid()])->assertCreated()->json('uuid');
        $this->signIn($checker)->postJson("/api/v1/recruitment/requisitions/$req/decision", ['decision' => 'open'])->assertOk();
        $this->signIn($this->admin)->postJson('/api/v1/recruitment/applications', ['candidate_uuid' => $candidate, 'requisition_uuid' => $req, 'source' => 'Referral'])->assertCreated();
    }

    private function limitedUser(string $role, array $permissions, string $scope = 'tenant'): User
    {
        $this->context($this->admin->tenant_id);
        $r = Role::create(['name' => $role, 'data_scope' => $scope, 'system' => false]);
        $r->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));
        $u = $this->userWithRole($role, strtolower(str_replace(' ', '', $role)).'@alpha.test');

        return $u;
    }

    public function test_upload_versions_list_and_download_with_audit(): void
    {
        $c = $this->candidate();
        $one = $this->post("/api/v1/recruitment/candidates/$c/resumes", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('version', 1);
        $this->post("/api/v1/recruitment/candidates/$c/resumes", ['file' => $this->docx()], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('version', 2);
        $this->assertArrayNotHasKey('path', $one->json());

        $this->context($this->admin->tenant_id);
        $row = Resume::where('version', 1)->firstOrFail();
        $this->assertStringNotContainsString('cv', basename($row->path), 'storage name must be random');
        $this->assertSame('hrm_private', $row->disk);
        Storage::disk('hrm_private')->assertExists($row->path);

        $this->getJson("/api/v1/recruitment/candidates/$c/resumes")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.version', 2);
        $res = $this->get("/api/v1/recruitment/candidates/$c/resumes/1/download")->assertOk();
        $this->assertSame('application/pdf', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', $res->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'resume.downloaded', 'resource' => $c]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'resume.uploaded', 'resource' => $c]);
        $this->getJson("/api/v1/recruitment/candidates/$c/resumes/9/download")->assertNotFound();
    }

    public function test_upload_validation(): void
    {
        $c = $this->candidate();
        $url = "/api/v1/recruitment/candidates/$c/resumes";
        $h = ['Accept' => 'application/json'];
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('cv.php', '<?php echo 1;')], $h)->assertUnprocessable();
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('cv.php.pdf', "%PDF-1.4\n")], $h)->assertUnprocessable();
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('cv.pdf', 'plain text pretending to be a pdf')], $h)->assertUnprocessable();
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('photo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='))], $h)->assertUnprocessable(); // image not a resume
        $this->post($url, ['file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')], $h)->assertUnprocessable();
        $this->post($url, [], $h)->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, Resume::count());
        $this->assertSame([], Storage::disk('hrm_private')->allFiles());
    }

    public function test_authorization_scope_and_tenant_isolation(): void
    {
        $c = $this->candidate();
        $this->post("/api/v1/recruitment/candidates/$c/resumes", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertCreated();
        $this->applyTo($c);

        $none = $this->limitedUser('No recruiting', ['people.view']);
        $this->signIn($none)->getJson("/api/v1/recruitment/candidates/$c/resumes")->assertForbidden();
        $viewer = $this->limitedUser('Viewer only', ['recruitment.view'], 'self');
        $this->signIn($viewer)->getJson("/api/v1/recruitment/candidates/$c/resumes")->assertNotFound(); // not on the hiring team
        $this->get("/api/v1/recruitment/candidates/$c/resumes/1/download")->assertNotFound();
        $wide = $this->limitedUser('Viewer wide', ['recruitment.view']);
        $this->signIn($wide)->getJson("/api/v1/recruitment/candidates/$c/resumes")->assertOk();
        $this->post("/api/v1/recruitment/candidates/$c/resumes", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertForbidden(); // view cannot upload
        $this->deleteJson("/api/v1/recruitment/candidates/$c/resumes/1")->assertForbidden();

        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson("/api/v1/recruitment/candidates/$c/resumes")->assertNotFound();
        $this->get("/api/v1/recruitment/candidates/$c/resumes/1/download")->assertNotFound();
        $this->post("/api/v1/recruitment/candidates/$c/resumes", ['file' => $this->pdf()], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_remove_and_anonymize_delete_files(): void
    {
        $c = $this->candidate();
        $h = ['Accept' => 'application/json'];
        $this->post("/api/v1/recruitment/candidates/$c/resumes", ['file' => $this->pdf()], $h)->assertCreated();
        $this->post("/api/v1/recruitment/candidates/$c/resumes", ['file' => $this->pdf('b.pdf', 'two')], $h)->assertCreated();
        $this->context($this->admin->tenant_id);
        $p1 = Resume::where('version', 1)->value('path');
        $p2 = Resume::where('version', 2)->value('path');

        $this->deleteJson("/api/v1/recruitment/candidates/$c/resumes/1")->assertNoContent();
        Storage::disk('hrm_private')->assertMissing($p1);
        $this->deleteJson("/api/v1/recruitment/candidates/$c/resumes/1")->assertConflict();
        $this->get("/api/v1/recruitment/candidates/$c/resumes/1/download")->assertNotFound();

        $this->postJson("/api/v1/recruitment/candidates/$c/anonymize", ['erasure_request' => true])->assertOk();
        Storage::disk('hrm_private')->assertMissing($p2);
        $this->post("/api/v1/recruitment/candidates/$c/resumes", ['file' => $this->pdf()], $h)->assertConflict();
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, Resume::whereNotNull('purged_at')->count());
        $this->assertSame(0, Resume::whereNotNull('path')->count());
    }

    public function test_retention_housekeeping_purges_expired_files_idempotently(): void
    {
        $c = $this->candidate();
        $keep = $this->candidate('keep@example.test');
        $h = ['Accept' => 'application/json'];
        $this->post("/api/v1/recruitment/candidates/$c/resumes", ['file' => $this->pdf()], $h)->assertCreated();
        $this->post("/api/v1/recruitment/candidates/$keep/resumes", ['file' => $this->pdf('k.pdf', 'keep')], $h)->assertCreated();
        $this->context($this->admin->tenant_id);
        $path = Resume::where('candidate_id', DB::table('recruitment_candidates')->where('uuid', $c)->value('id'))->value('path');
        $keepPath = Resume::where('candidate_id', DB::table('recruitment_candidates')->where('uuid', $keep)->value('id'))->value('path');
        $housekeeping = app(RecruitmentHousekeeping::class);
        $this->assertSame(0, $housekeeping->run()['resumes_purged']);
        Storage::disk('hrm_private')->assertExists($path);

        DB::table('recruitment_candidates')->where('uuid', $c)->update(['retention_until' => '2026-01-01']);
        $this->assertSame(1, $housekeeping->run()['resumes_purged']);
        Storage::disk('hrm_private')->assertMissing($path);
        Storage::disk('hrm_private')->assertExists($keepPath);
        $this->assertSame(0, $housekeeping->run()['resumes_purged']); // repeat-safe
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'candidate.files_purged')->where('resource', $c)->count());
    }
}
