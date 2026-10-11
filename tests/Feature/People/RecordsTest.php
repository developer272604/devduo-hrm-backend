<?php

namespace Tests\Feature\People;

use App\Domain\Access\PeopleSettings;
use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\People\Countries\CountryConfig;
use App\Domain\People\Models\EmployeeDocument;
use App\Domain\People\Models\Person;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

/** Country configuration, addresses, private documents and photos. */
class RecordsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private $emp;

    private $mgr;

    private $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        Storage::fake('hrm_private');
        $this->mgr = $this->hire('Maya Chen', ['role' => 'Manager']);
        $this->emp = $this->hire('Asha Rao', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->other = $this->hire('Olive Other', ['role' => 'Team member', 'department' => 1]);
    }

    private function url(string $suffix = '', $employee = null): string
    {
        return '/api/v1/employees/'.($employee ?? $this->emp)->uuid.$suffix;
    }

    private function as($employee): static
    {
        return $this->signIn($employee instanceof \App\Models\User ? $employee : $employee->person->user);
    }

    public static function pdf(string $name = 'scan.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    public static function png(string $name = 'photo.png', int $w = 1200, int $h = 800): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagepng($img);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    // ---- Country configuration ----------------------------------------------------------------

    public function test_default_packs_cover_the_required_countries_with_complete_structure(): void
    {
        $countries = app(CountryConfig::class);
        foreach (['IN', 'US', 'GB', 'AE', 'SG', 'DE', 'AU', 'CA'] as $code) {
            $pack = $countries->for($code);
            foreach (['name', 'currency', 'timezone', 'locale', 'date_format', 'number_format', 'phone_code', 'name_format', 'working_language'] as $field) {
                $this->assertNotEmpty($pack[$field], "$code.$field");
            }
            $this->assertNotEmpty($pack['address_format']);
            $this->assertNotEmpty($pack['bank_schema']);
            $this->assertContains('passport', array_column($pack['identifier_types'], 'key'));
            foreach ($pack['identifier_types'] as $type) {
                $this->assertSame('highly_sensitive', $type['classification']);
            }
        }
        $this->assertSame(['pan', 'aadhaar'], array_slice(array_column($countries->identifierTypes('IN'), 'key'), 0, 2));
        $this->assertContains('ssn', array_column($countries->identifierTypes('US'), 'key'));
        $this->assertContains('ni_number', array_column($countries->identifierTypes('GB'), 'key'));
        $this->assertSame(['account_number', 'ifsc'], collect($countries->bankSchema('IN'))->whereIn('key', ['ifsc', 'account_number'])->pluck('key')->sort()->values()->all());
        $this->assertSame('County', collect($countries->addressRules('GB'))->firstWhere('key', 'region')['label']);
        $this->assertSame('Emirate', collect($countries->addressRules('AE'))->firstWhere('key', 'region')['label']);
        $this->assertSame('State', collect($countries->addressRules('IN'))->firstWhere('key', 'region')['label']);
    }

    public function test_api_lists_enabled_countries_and_merges_tenant_overrides(): void
    {
        $this->signIn($this->admin);
        $this->getJson('/api/v1/people/countries')->assertOk()->assertJsonFragment(['code' => 'IN', 'name' => 'India']);
        $this->putJson('/api/v1/people/countries/IN', ['currency' => 'inr', 'identifier_types' => [['key' => 'pan', 'label' => 'PAN (permanent account number)', 'pattern' => '^[A-Z]{5}\d{4}[A-Z]$'], ['key' => 'aadhaar', 'disabled' => true]]])->assertOk()
            ->assertJsonPath('currency', 'INR')->assertJsonPath('source', 'override');
        $show = $this->getJson('/api/v1/people/countries/IN')->assertOk();
        $keys = collect($show->json('identifier_types'))->pluck('key');
        $this->assertFalse($keys->contains('aadhaar'));
        $this->assertTrue($keys->contains('passport'));
        $this->assertSame('PAN (permanent account number)', collect($show->json('identifier_types'))->firstWhere('key', 'pan')['label']);
        $this->assertSame('India', $show->json('name'), 'untouched scalars keep the default');
        $this->context($this->admin->tenant_id);
        $this->assertTrue(AuditLog::where('resource', 'country:IN')->where('action', 'updated')->exists());
        $this->signIn($this->admin);
        $this->deleteJson('/api/v1/people/countries/IN')->assertNoContent();
        $this->assertContains('aadhaar', array_column($this->getJson('/api/v1/people/countries/IN')->json('identifier_types'), 'key'));
    }

    public function test_new_country_can_be_added_by_override_only_and_can_be_disabled(): void
    {
        $this->signIn($this->admin);
        $this->getJson('/api/v1/people/countries/NZ')->assertNotFound();
        $this->putJson('/api/v1/people/countries/NZ', ['currency' => 'NZD'])->assertStatus(422); // a new country needs a name
        $this->putJson('/api/v1/people/countries/NZ', [
            'name' => 'New Zealand', 'currency' => 'NZD', 'timezone' => 'Pacific/Auckland', 'phone_code' => '+64',
            'address_format' => [['key' => 'line1', 'label' => 'Street', 'required' => true], ['key' => 'suburb', 'label' => 'Suburb', 'required' => true], ['key' => 'postal_code', 'label' => 'Postcode', 'required' => true, 'pattern' => '^\d{4}$']],
            'identifier_types' => [['key' => 'ird', 'label' => 'IRD number', 'kind' => 'identity', 'pattern' => '^\d{8,9}$', 'classification' => 'highly_sensitive', 'mask' => 'last4']],
            'bank_schema' => [['key' => 'account_number', 'label' => 'Account number', 'required' => true, 'pattern' => '^\d{15,16}$', 'primary' => true]],
        ])->assertOk()->assertJsonPath('source', 'custom');
        $this->getJson('/api/v1/people/countries')->assertJsonFragment(['code' => 'NZ', 'name' => 'New Zealand']);

        $this->postJson($this->url('/addresses'), ['type' => 'current', 'line1' => '1 Queen St', 'country' => 'NZ', 'postal_code' => '1010', 'extra' => ['suburb' => 'Auckland CBD']])->assertCreated()->assertJsonPath('extra.suburb', 'Auckland CBD');
        $this->postJson($this->url('/addresses'), ['type' => 'mailing', 'line1' => '1 Queen St', 'country' => 'NZ', 'postal_code' => '1010'])->assertStatus(422)->assertJsonValidationErrors('extra.suburb');
        $this->postJson($this->url('/identity'), ['type' => 'ird', 'country' => 'NZ', 'number' => '49-091-850'])->assertCreated()->assertJsonPath('number_masked', '••••1850');

        $this->putJson('/api/v1/people/countries/NZ', ['enabled' => false])->assertOk();
        $this->assertNotContains('NZ', array_column($this->getJson('/api/v1/people/countries')->json('data'), 'code'));
        $this->postJson($this->url('/addresses'), ['type' => 'other', 'line1' => 'x', 'country' => 'NZ', 'postal_code' => '1010', 'extra' => ['suburb' => 'x']])->assertStatus(422)->assertJsonValidationErrors('country');
    }

    public function test_country_override_validation_and_permissions(): void
    {
        $this->as($this->emp)->putJson('/api/v1/people/countries/IN', ['currency' => 'USD'])->assertForbidden();
        $this->getJson('/api/v1/people/countries/IN')->assertOk();
        $this->signIn($this->admin);
        $this->putJson('/api/v1/people/countries/IN', ['bank_schema' => [['key' => 'Bad Key', 'label' => 'x']]])->assertStatus(422);
        $this->putJson('/api/v1/people/countries/IN', ['identifier_types' => [['key' => 'pan', 'label' => 'PAN', 'pattern' => '([unclosed']]])->assertStatus(422)->assertJsonValidationErrors('identifier_types.0.pattern');
        $this->putJson('/api/v1/people/countries/IN', ['identifier_types' => 'nope'])->assertStatus(422);
        $this->putJson('/api/v1/people/countries/ZZZ', ['name' => 'x'])->assertStatus(422);
    }

    // ---- Addresses ---------------------------------------------------------------------------

    public function test_address_validation_is_country_driven(): void
    {
        $this->signIn($this->admin);
        $in = ['type' => 'current', 'line1' => '12 MG Road', 'city' => 'Bengaluru', 'region' => 'Karnataka', 'postal_code' => '560001', 'country' => 'IN'];
        $this->postJson($this->url('/addresses'), $in)->assertCreated()->assertJsonPath('region', 'KA')->assertJsonPath('is_primary', true);
        $this->postJson($this->url('/addresses'), ['postal_code' => '56001'] + $in)->assertStatus(422)->assertJsonValidationErrors('postal_code');
        $this->postJson($this->url('/addresses'), ['region' => 'Atlantis'] + $in)->assertStatus(422)->assertJsonValidationErrors('region');
        $this->postJson($this->url('/addresses'), ['region' => null] + $in)->assertStatus(422)->assertJsonValidationErrors('region');

        $gb = ['type' => 'mailing', 'line1' => '10 Downing Street', 'city' => 'London', 'country' => 'GB'];
        $this->postJson($this->url('/addresses'), $gb + ['postal_code' => 'sw1a 2aa'])->assertCreated()->assertJsonPath('postal_code', 'SW1A 2AA')->assertJsonPath('region', null);
        $this->postJson($this->url('/addresses'), $gb + ['postal_code' => '12345'])->assertStatus(422)->assertJsonValidationErrors('postal_code');

        $us = ['type' => 'other', 'line1' => '1 Main St', 'city' => 'Austin', 'country' => 'US', 'region' => 'TX'];
        $this->postJson($this->url('/addresses'), $us + ['postal_code' => '78701-1234'])->assertCreated();
        $this->postJson($this->url('/addresses'), $us + ['postal_code' => 'ABCDE'])->assertStatus(422);

        $this->postJson($this->url('/addresses'), ['type' => 'bogus', 'line1' => 'x', 'country' => 'IN'])->assertStatus(422);
        $this->postJson($this->url('/addresses'), ['type' => 'current', 'line1' => 'x', 'country' => 'XX', 'city' => 'Somewhere'])->assertCreated(); // unknown country uses the generic pack
        $this->postJson($this->url('/addresses'), ['type' => 'current', 'line1' => 'x', 'country' => 'India'])->assertStatus(422);
    }

    public function test_one_primary_address_per_type_and_primary_moves_on_delete(): void
    {
        $this->signIn($this->admin);
        $base = ['type' => 'current', 'line1' => 'A', 'city' => 'Pune', 'region' => 'MH', 'postal_code' => '411001', 'country' => 'IN'];
        $a = $this->postJson($this->url('/addresses'), $base)->json('uuid');
        $b = $this->postJson($this->url('/addresses'), ['line1' => 'B', 'is_primary' => true] + $base)->json('uuid');
        $c = $this->postJson($this->url('/addresses'), ['type' => 'permanent', 'line1' => 'C'] + $base)->json('uuid');
        $list = collect($this->getJson($this->url('/addresses'))->json('data'));
        $this->assertFalse($list->firstWhere('uuid', $a)['is_primary']);
        $this->assertTrue($list->firstWhere('uuid', $b)['is_primary']);
        $this->assertTrue($list->firstWhere('uuid', $c)['is_primary'], 'a different type has its own primary');
        $this->patchJson($this->url('/addresses/'.$a), ['is_primary' => true])->assertOk();
        $list = collect($this->getJson($this->url('/addresses'))->json('data'));
        $this->assertSame(1, $list->where('type', 'current')->where('is_primary', true)->count());
        $this->deleteJson($this->url('/addresses/'.$a))->assertNoContent();
        $this->assertTrue(collect($this->getJson($this->url('/addresses'))->json('data'))->firstWhere('uuid', $b)['is_primary']);
        $this->patchJson($this->url('/addresses/'.$b), ['effective_from' => '2026-05-01', 'effective_to' => '2026-01-01'])->assertStatus(422);
    }

    public function test_address_edit_rules_direct_request_none(): void
    {
        $payload = ['type' => 'current', 'line1' => '5 Park St', 'city' => 'Kolkata', 'region' => 'WB', 'postal_code' => '700016', 'country' => 'IN'];
        $this->as($this->emp)->postJson($this->url('/addresses'), $payload)->assertCreated(); // default rule: direct
        $this->as($this->mgr)->getJson($this->url('/addresses'))->assertForbidden(); // managers do not see employee-class data
        $this->as($this->mgr)->postJson($this->url('/addresses'), $payload)->assertForbidden();
        $this->as($this->other)->getJson($this->url('/addresses'))->assertNotFound(); // outside the data scope

        $this->context($this->admin->tenant_id);
        PeopleSettings::save(['edit_rules' => ['address' => 'request']]);
        $this->as($this->emp)->postJson($this->url('/addresses'), $payload)->assertStatus(409)->assertJsonPath('code', 'change_request_required')->assertJsonPath('section', 'address');
        $this->context($this->admin->tenant_id);
        PeopleSettings::save(['edit_rules' => ['address' => 'none']]);
        $this->as($this->emp)->postJson($this->url('/addresses'), $payload)->assertForbidden();
        $this->signIn($this->admin)->postJson($this->url('/addresses'), ['line1' => 'HR entered'] + $payload)->assertCreated(); // HR is always direct
    }

    // ---- Documents ---------------------------------------------------------------------------

    private function upload($user, array $extra = [], ?UploadedFile $file = null, $employee = null)
    {
        $this->as($user);

        return $this->post($this->url('/documents', $employee), ['file' => $file ?? self::pdf(), 'document_type' => 'offer_letter'] + $extra, ['Accept' => 'application/json']);
    }

    public function test_document_upload_version_download_and_private_storage(): void
    {
        $res = $this->upload($this->admin, ['title' => 'Offer', 'employee_visible' => true])->assertCreated();
        $uuid = $res->json('uuid');
        $this->assertSame(1, $res->json('current_version.version'));
        $this->assertSame('pending', $res->json('verification_status'));
        $res->assertJsonMissingPath('current_version.path')->assertJsonMissingPath('current_version.disk');
        $this->assertStringNotContainsString('url', strtolower(json_encode(array_keys($res->json()))));

        $this->context($this->admin->tenant_id);
        $version = DB::table('employee_document_versions')->first();
        $this->assertSame('hrm_private', $version->disk);
        $this->assertMatchesRegularExpression('#^'.$this->admin->tenant_id.'/'.Person::first()->where('id', $this->emp->person_id)->value('uuid').'/[a-z0-9]{40}\.pdf$#', $version->path);
        $this->assertStringNotContainsString('scan', $version->path, 'the client file name is never part of the path');
        Storage::disk('hrm_private')->assertExists($version->path);
        $this->assertSame(hash('sha256', file_get_contents(Storage::disk('hrm_private')->path($version->path))), $version->sha256);
        $this->assertSame([], config('filesystems.disks.hrm_private.url') ? ['url'] : [], 'the private disk has no public URL');

        // Version 2 (append-only).
        $this->signIn($this->admin);
        $this->post($this->url('/documents/'.$uuid.'/versions'), ['file' => self::pdf('v2.pdf')], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('current_version.version', 2)
            ->assertJsonCount(2, 'versions');
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, DB::table('employee_document_versions')->count());
        $this->assertSame(2, (int) DB::table('employee_documents')->value('current_version_id') ? 2 : 0);

        $this->signIn($this->admin);
        $dl = $this->get($this->url('/documents/'.$uuid.'/download'))->assertOk();
        $this->assertStringContainsString('%PDF-1.4', $dl->streamedContent());
        $this->assertStringContainsString('attachment', $dl->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $dl->headers->get('X-Content-Type-Options'));
        $this->get($this->url('/documents/'.$uuid.'/download?version=1'))->assertOk();
        $this->get($this->url('/documents/'.$uuid.'/download?version=9'))->assertNotFound();
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, AuditLog::where('action', 'downloaded')->count(), 'confidential downloads are audited');
        $this->assertTrue(\App\Domain\Events\DomainEvent::where('type', 'DocumentUploaded')->exists());
    }

    public function test_document_visibility_matrix(): void
    {
        $visible = $this->upload($this->admin, ['title' => 'Visible', 'employee_visible' => true])->json('uuid');
        $hidden = $this->upload($this->admin, ['title' => 'HR only', 'employee_visible' => false])->json('uuid');

        $this->signIn($this->admin);
        $this->assertCount(2, $this->getJson($this->url('/documents'))->json('data'));

        $this->as($this->emp);
        $list = $this->getJson($this->url('/documents'))->assertOk();
        $this->assertSame([$visible], array_column($list->json('data'), 'uuid'));
        $this->get($this->url('/documents/'.$hidden.'/download'))->assertNotFound();
        $this->getJson($this->url('/documents/'.$hidden))->assertNotFound();
        $this->get($this->url('/documents/'.$visible.'/download'))->assertOk();

        $this->as($this->mgr);
        $this->getJson($this->url('/documents'))->assertForbidden();
        $this->get($this->url('/documents/'.$visible.'/download'))->assertForbidden();

        $this->as($this->other);
        $this->getJson($this->url('/documents'))->assertNotFound();
        $this->get($this->url('/documents/'.$visible.'/download'))->assertNotFound();
    }

    public function test_employee_upload_follows_edit_rule_and_cannot_touch_verified_documents(): void
    {
        $this->upload($this->emp)->assertStatus(409)->assertJsonPath('code', 'change_request_required')->assertJsonPath('section', 'document');
        $this->context($this->admin->tenant_id);
        PeopleSettings::save(['edit_rules' => ['document' => 'direct']]);
        $doc = $this->upload($this->emp, ['employee_visible' => false])->assertCreated()->assertJsonPath('employee_visible', true)->assertJsonPath('verification_status', 'pending')->json('uuid');
        $this->as($this->emp)->patchJson($this->url('/documents/'.$doc), ['title' => 'Mine'])->assertOk()->assertJsonPath('title', 'Mine');
        $this->patchJson($this->url('/documents/'.$doc), ['employee_visible' => false])->assertForbidden();
        $this->as($this->emp)->postJson($this->url('/documents/'.$doc.'/verify'), ['status' => 'verified'])->assertForbidden();
        $this->as($this->emp)->deleteJson($this->url('/documents/'.$doc))->assertForbidden();

        $this->signIn($this->admin)->postJson($this->url('/documents/'.$doc.'/verify'), ['status' => 'verified'])->assertOk()->assertJsonPath('verification_status', 'verified');
        $this->as($this->emp)->patchJson($this->url('/documents/'.$doc), ['title' => 'Changed'])->assertForbidden();
        $this->post($this->url('/documents/'.$doc.'/versions'), ['file' => self::pdf()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_verify_reject_delete_and_audit_trail(): void
    {
        $doc = $this->upload($this->admin)->json('uuid');
        $this->signIn($this->admin);
        $res = $this->postJson($this->url('/documents/'.$doc.'/verify'), ['status' => 'verified'])->assertOk();
        $this->assertNotNull($res->json('verified_by'));
        $this->postJson($this->url('/documents/'.$doc.'/verify'), ['status' => 'maybe'])->assertStatus(422);
        $this->postJson($this->url('/documents/'.$doc.'/verify'), ['status' => 'rejected'])->assertOk();
        $this->deleteJson($this->url('/documents/'.$doc))->assertNoContent();
        $this->getJson($this->url('/documents'))->assertJsonCount(0, 'data');
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, EmployeeDocument::withTrashed()->count(), 'delete is soft');
        $this->assertSame(1, DB::table('employee_document_versions')->count(), 'versions are retained');
        foreach (['uploaded', 'verified', 'rejected', 'deleted'] as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->where('resource', 'employee:'.$this->emp->uuid)->exists(), $action);
        }
        $types = \App\Domain\Events\DomainEvent::pluck('type')->all();
        $this->assertContains('DocumentVerified', $types);
        // The manager (people.view only) can neither verify nor delete.
        $this->as($this->mgr)->postJson($this->url('/documents/'.$doc.'/verify'), ['status' => 'verified'])->assertForbidden();
        $this->deleteJson($this->url('/documents/'.$doc))->assertForbidden();
    }

    public function test_real_mime_is_checked_not_the_extension(): void
    {
        $php = UploadedFile::fake()->createWithContent('resume.pdf', "<?php system(\$_GET['c']); ?>\n");
        $this->upload($this->admin, [], $php)->assertStatus(422)->assertJsonValidationErrors('file');
        $text = UploadedFile::fake()->createWithContent('notes.pdf', "just some plain text, not a pdf\n");
        $this->upload($this->admin, [], $text)->assertStatus(422);
        $exe = UploadedFile::fake()->createWithContent('tool.exe', "MZ\x90\x00\x03\x00");
        $this->upload($this->admin, [], $exe)->assertStatus(422);
        $double = UploadedFile::fake()->createWithContent('invoice.php.pdf', "%PDF-1.4\n%%EOF\n");
        $this->upload($this->admin, [], $double)->assertStatus(422)->assertJsonValidationErrors('file');
        $noExt = UploadedFile::fake()->createWithContent('invoice', "%PDF-1.4\n%%EOF\n");
        $this->upload($this->admin, [], $noExt)->assertStatus(422);
        $fakeDocx = UploadedFile::fake()->createWithContent('contract.docx', "%PDF-1.4\n%%EOF\n");
        $this->upload($this->admin, [], $fakeDocx)->assertStatus(422);
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->upload($this->admin, [], $svg)->assertStatus(422);
        $png = self::png('scan.pdf'); // a PNG renamed to .pdf
        $this->upload($this->admin, [], $png)->assertStatus(422);
        $this->assertSame([], Storage::disk('hrm_private')->allFiles());
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, EmployeeDocument::count());
        // Genuine image documents are fine.
        $this->upload($this->admin, [], self::png('passport.png'))->assertCreated()->assertJsonPath('current_version.mime', 'image/png')->assertJsonPath('previewable', true);
    }

    public function test_oversized_file_and_expiry_requirements(): void
    {
        $big = UploadedFile::fake()->createWithContent('big.pdf', "%PDF-1.4\n".str_repeat('A', 10 * 1024 * 1024 + 10));
        $this->upload($this->admin, [], $big)->assertStatus(422)->assertJsonPath('errors.file.0', 'The file is larger than 10 MB.');
        $this->signIn($this->admin);
        $this->post($this->url('/documents'), ['file' => self::pdf(), 'document_type' => 'passport'], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('expiry_date');
        $this->post($this->url('/documents'), ['file' => self::pdf(), 'document_type' => 'passport', 'expiry_date' => '2030-01-01', 'issue_date' => '2031-01-01'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post($this->url('/documents'), ['file' => self::pdf(), 'document_type' => 'nope'], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('document_type');
        $this->post($this->url('/documents'), ['file' => self::pdf(), 'document_type' => 'passport', 'expiry_date' => '2030-01-01', 'tags' => ['id', 'scan']], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('tags', ['id', 'scan']);
    }

    public function test_document_types_are_seeded_lazily_and_idempotently_and_managed_by_settings_permission(): void
    {
        $this->signIn($this->admin);
        $first = $this->getJson('/api/v1/people/document-types')->assertOk()->json('data');
        $this->getJson('/api/v1/people/document-types');
        $this->context($this->admin->tenant_id);
        $this->assertSame(count($first), \App\Domain\People\Models\DocumentType::count());
        $this->signIn($this->admin);
        $this->postJson('/api/v1/people/document-types', ['code' => 'safety_card', 'name' => 'Safety card', 'category' => 'certificate', 'requires_expiry' => true, 'mandatory' => true, 'mandatory_for' => ['countries' => ['in'], 'employee_types' => []]])
            ->assertCreated()->assertJsonPath('mandatory_for.countries', ['IN']);
        $this->postJson('/api/v1/people/document-types', ['code' => 'safety_card', 'name' => 'Dup', 'category' => 'other'])->assertStatus(422);
        $this->postJson('/api/v1/people/document-types', ['code' => 'weird', 'name' => 'x', 'category' => 'nonsense'])->assertStatus(422);
        $this->patchJson('/api/v1/people/document-types/offer_letter', ['active' => false])->assertOk();
        $this->post($this->url('/documents'), ['file' => self::pdf(), 'document_type' => 'offer_letter'], ['Accept' => 'application/json'])->assertStatus(422);
        $this->getJson($this->url('/documents/requirements'))->assertOk()->assertJsonPath('data.0.code', 'safety_card')->assertJsonPath('data.0.provided', false);
        $this->deleteJson('/api/v1/people/document-types/passport')->assertStatus(422);
        $this->deleteJson('/api/v1/people/document-types/safety_card')->assertNoContent();
        // Employees see active types only and cannot change them.
        $this->as($this->emp);
        $codes = array_column($this->getJson('/api/v1/people/document-types')->json('data'), 'code');
        $this->assertContains('passport', $codes);
        $this->assertNotContains('offer_letter', $codes);
        $this->postJson('/api/v1/people/document-types', ['code' => 'x_type', 'name' => 'X', 'category' => 'other'])->assertForbidden();
    }

    // ---- Photo -------------------------------------------------------------------------------

    public function test_photo_is_resized_reencoded_private_and_honours_directory_privacy(): void
    {
        $this->as($this->emp);
        $this->post($this->url('/photo'), ['file' => self::png('me.png', 1600, 900)], ['Accept' => 'application/json'])->assertCreated();
        $this->context($this->admin->tenant_id);
        $path = Person::where('id', $this->emp->person_id)->value('photo_path');
        $this->assertNotEmpty($path);
        $this->assertStringStartsWith($this->admin->tenant_id.'/', $path);
        $bytes = Storage::disk('hrm_private')->get($path);
        [$w, $h, $type] = getimagesizefromstring($bytes);
        $this->assertSame(IMAGETYPE_JPEG, $type);
        $this->assertLessThanOrEqual(512, max($w, $h));
        $this->assertSame(512, $w);
        $this->assertSame(288, $h);

        $this->as($this->emp)->get($this->url('/photo'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->as($this->other)->get($this->url('/photo'))->assertOk(); // directory privacy default: photos visible

        $this->context($this->admin->tenant_id);
        PeopleSettings::save(['directory_privacy' => ['photo' => false]]);
        $this->as($this->other)->get($this->url('/photo'))->assertNotFound();
        $this->as($this->emp)->get($this->url('/photo'))->assertOk();
        $this->signIn($this->admin)->get($this->url('/photo'))->assertOk();

        // Other employees cannot change it; non-images and fake images are rejected; delete removes the file.
        $this->as($this->other)->post($this->url('/photo'), ['file' => self::png()], ['Accept' => 'application/json'])->assertNotFound();
        $this->as($this->emp)->post($this->url('/photo'), ['file' => UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post($this->url('/photo'), ['file' => self::pdf('x.pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->deleteJson($this->url('/photo'))->assertNoContent();
        $this->get($this->url('/photo'))->assertNotFound();
        Storage::disk('hrm_private')->assertMissing($path);
    }

    public function test_photo_metadata_is_stripped_and_upload_by_hr_needs_edit_rights(): void
    {
        $img = imagecreatetruecolor(100, 100);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        $withComment = substr($jpeg, 0, 2)."\xFF\xFE\x00\x0ESECRET-NOTE\x00".substr($jpeg, 2);
        $this->signIn($this->admin)->post($this->url('/photo'), ['file' => UploadedFile::fake()->createWithContent('a.jpg', $withComment)], ['Accept' => 'application/json'])->assertCreated();
        $this->context($this->admin->tenant_id);
        $stored = Storage::disk('hrm_private')->get(Person::where('id', $this->emp->person_id)->value('photo_path'));
        $this->assertStringNotContainsString('SECRET-NOTE', $stored);
        $this->as($this->mgr)->post($this->url('/photo'), ['file' => self::png()], ['Accept' => 'application/json'])->assertForbidden();
    }

    // ---- Isolation ---------------------------------------------------------------------------

    public function test_tenants_are_isolated_and_no_ids_leak(): void
    {
        $doc = $this->upload($this->admin)->json('uuid');
        $this->context($this->admin->tenant_id);
        $beta = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        foreach (['/documents', '/addresses', '/identity', '/work-authorizations', '/bank-accounts'] as $path) {
            $this->getJson($this->url($path))->assertNotFound();
        }
        $this->get($this->url('/documents/'.$doc.'/download'))->assertNotFound();
        $this->get($this->url('/photo'))->assertNotFound();
        $this->assertSame('Beta', collect($this->getJson('/api/v1/people/countries')->json('data'))->isNotEmpty() ? 'Beta' : '');
        $this->putJson('/api/v1/people/countries/NZ', ['name' => 'New Zealand'])->assertOk();
        $this->signIn($this->admin);
        $this->getJson('/api/v1/people/countries/NZ')->assertNotFound(); // overrides never cross tenants
    }
}
