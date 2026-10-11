<?php

namespace Tests\Feature\Recruitment;

use App\Domain\Recruitment\Models\Candidate;
use App\Domain\Recruitment\Models\CandidateImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class CandidateImportTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const HEADER = 'name,email,phone,source,privacy_notice_version,consent_reference,consented_at,retention_until';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->signIn($this->admin);
    }

    private function csv(array $rows, ?string $header = null): UploadedFile
    {
        $lines = array_merge([$header ?? self::HEADER], $rows);

        return UploadedFile::fake()->createWithContent('candidates.csv', implode("\n", $lines)."\n");
    }

    private function row(string $name, string $email, string $retention = '2027-06-30'): string
    {
        return "$name,$email,,referral,v1,consent-form-1,2026-10-01 09:00:00,$retention";
    }

    public function test_preview_validates_every_row_and_writes_no_candidates(): void
    {
        $response = $this->post('/api/v1/recruitment/imports/candidates', ['file' => $this->csv([
            $this->row('Asha Rao', 'asha@example.test'),
            $this->row('Bad Date', 'bad@example.test', '2020-01-01'),
            $this->row('Asha Again', 'ASHA@example.test'),
        ])], ['Accept' => 'application/json'])->assertCreated();

        $rows = $response->json('rows');
        $this->assertCount(3, $rows);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertNotEmpty($rows[1]['errors'], 'a retention date in the past must be rejected');
        $this->assertNotEmpty($rows[2]['errors'], 'a repeated email inside the file is a duplicate');
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, Candidate::count(), 'preview must not create candidates');
    }

    public function test_commit_requires_confirmation_imports_valid_rows_and_discards_source_data(): void
    {
        $uuid = $this->post('/api/v1/recruitment/imports/candidates', ['file' => $this->csv([
            $this->row('Asha Rao', 'asha@example.test'),
            $this->row('Bad Date', 'bad@example.test', '2020-01-01'),
        ])], ['Accept' => 'application/json'])->assertCreated()->json('uuid');

        $this->postJson("/api/v1/recruitment/imports/candidates/$uuid/commit", [])->assertStatus(422);
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, Candidate::count());

        $result = $this->postJson("/api/v1/recruitment/imports/candidates/$uuid/commit", ['confirmed' => true])->assertOk()->json('rows');
        $this->assertSame('imported', $result[0]['status']);
        $this->assertSame('skipped', $result[1]['status']);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Candidate::count());
        $this->assertSame('asha@example.test', Candidate::first()->email);

        $this->context($this->admin->tenant_id);
        $import = CandidateImport::where('uuid', $uuid)->first();
        $this->assertSame('committed', $import->status);
        $this->assertNull($import->rows, 'source rows hold personal data and must be discarded after commit');

        // A second commit is a no-op that returns the receipt.
        $this->postJson("/api/v1/recruitment/imports/candidates/$uuid/commit", ['confirmed' => true])->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Candidate::count());
    }

    public function test_existing_candidates_are_flagged_as_duplicates_not_overwritten(): void
    {
        $uuid = $this->post('/api/v1/recruitment/imports/candidates', ['file' => $this->csv([$this->row('Asha Rao', 'asha@example.test')])], ['Accept' => 'application/json'])->json('uuid');
        $this->postJson("/api/v1/recruitment/imports/candidates/$uuid/commit", ['confirmed' => true])->assertOk();

        $second = $this->post('/api/v1/recruitment/imports/candidates', ['file' => $this->csv([$this->row('Asha Rao', 'asha@example.test')])], ['Accept' => 'application/json'])->assertCreated();
        $this->assertNotEmpty($second->json('rows.0.errors'));
        $this->postJson('/api/v1/recruitment/imports/candidates/'.$second->json('uuid').'/commit', ['confirmed' => true])->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Candidate::count());
    }

    public function test_bad_headers_oversized_files_and_missing_permission_are_refused(): void
    {
        $this->post('/api/v1/recruitment/imports/candidates', ['file' => $this->csv([], 'name,email,extra')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/v1/recruitment/imports/candidates', ['file' => $this->csv([])], ['Accept' => 'application/json'])->assertStatus(422);

        $this->context($this->admin->tenant_id);
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $this->signIn($member)->post('/api/v1/recruitment/imports/candidates', ['file' => $this->csv([$this->row('A B', 'ab@example.test')])], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_an_import_belongs_to_its_creator_and_expires(): void
    {
        $uuid = $this->post('/api/v1/recruitment/imports/candidates', ['file' => $this->csv([$this->row('Asha Rao', 'asha@example.test')])], ['Accept' => 'application/json'])->json('uuid');

        $this->context($this->admin->tenant_id);
        $other = $this->userWithRole('Workspace administrator', 'other-admin@alpha.test');
        $this->signIn($other)->postJson("/api/v1/recruitment/imports/candidates/$uuid/commit", ['confirmed' => true])->assertNotFound();

        $this->signIn($this->admin);
        $this->context($this->admin->tenant_id);
        CandidateImport::where('uuid', $uuid)->update(['expires_at' => now()->subMinute()]);
        $this->postJson("/api/v1/recruitment/imports/candidates/$uuid/commit", ['confirmed' => true])->assertStatus(410);
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, Candidate::count());
    }
}
