<?php

namespace Tests\Feature\People;

use App\Domain\Access\PeopleSettings;
use App\Domain\Events\DomainEvent;
use App\Domain\People\Records\IdentityHash;
use App\Domain\People\Records\IdentityService;
use App\Models\AuditLog;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

/** Identity, work authorization, bank accounts and the expiry command. */
class RecordsSensitiveTest extends TestCase
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

    private function as($who): static
    {
        return $this->signIn($who instanceof \App\Models\User ? $who : $who->person->user);
    }

    private function dump(string $table): string
    {
        return json_encode(DB::table($table)->get());
    }

    public function test_generic_local_disk_does_not_serve_private_application_files(): void
    {
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
        $this->assertStringStartsWith(storage_path('app/private'), config('filesystems.disks.hrm_private.root'));
        $this->assertSame(storage_path('app/public'), config('filesystems.links.'.public_path('storage')));
    }

    // ---- Identity ------------------------------------------------------------------------------

    public function test_identity_is_encrypted_hashed_masked_and_validated_per_country(): void
    {
        $this->signIn($this->admin);
        $res = $this->postJson($this->url('/identity'), ['type' => 'pan', 'country' => 'IN', 'number' => 'abcde 1234-f', 'issue_date' => '2020-01-01'])->assertCreated();
        $res->assertJsonPath('number_masked', '••••234F')->assertJsonPath('verification_status', 'unverified')->assertJsonMissingPath('number');
        $this->assertStringNotContainsString('ABCDE1234F', $res->getContent());

        $this->context($this->admin->tenant_id);
        $row = DB::table('identity_documents')->first();
        $this->assertSame('ABCDE1234F', Crypt::decryptString($row->number_encrypted));
        $this->assertSame(IdentityHash::for('abcde-1234 f', $this->admin->tenant_id), $row->number_hash);
        $this->assertSame('234F', $row->number_last4);
        $this->assertStringNotContainsString('ABCDE1234F', $this->dump('identity_documents'));
        $this->assertStringNotContainsString('ABCDE1234F', $this->dump('audit_logs').$this->dump('domain_events'));

        $this->signIn($this->admin);
        foreach ([['pan', 'IN', 'ABCDE12345'], ['aadhaar', 'IN', '123456789012'], ['ssn', 'IN', '123-45-6789'], ['nope', 'IN', 'ABCDE1234F'], ['ssn', 'US', '666-12-3456']] as [$type, $country, $number]) {
            $r = $this->postJson($this->url('/identity'), compact('type', 'country', 'number'))->assertStatus(422);
            $this->assertStringNotContainsString(preg_replace('/\D/', '', $number) ?: $number, json_encode($r->json()), 'validation errors never echo the value');
        }
        $this->postJson($this->url('/identity'), ['type' => 'ssn', 'country' => 'US', 'number' => '123-45-6789'])->assertCreated()->assertJsonPath('number_masked', '••••6789');
        $this->postJson($this->url('/identity'), ['type' => 'ni_number', 'country' => 'GB', 'number' => 'AB 12 34 56 C'])->assertCreated();
        $this->postJson($this->url('/identity'), ['type' => 'sin', 'country' => 'CA', 'number' => '046 454 286'])->assertCreated(); // Luhn-valid
        $this->postJson($this->url('/identity'), ['type' => 'sin', 'country' => 'CA', 'number' => '046 454 287'])->assertStatus(422);
        $this->postJson($this->url('/identity'), ['type' => 'pan', 'country' => 'IN', 'number' => 'ABCDE1234F'])->assertStatus(422)->assertJsonValidationErrors('number'); // same person, same number
        $this->postJson($this->url('/identity'), ['type' => 'passport', 'country' => 'IN', 'number' => 'K1234567', 'issue_date' => '2020-01-01', 'expiry_date' => '2019-01-01'])->assertStatus(422);
    }

    public function test_identity_lists_are_masked_and_reveal_is_permissioned_and_audited(): void
    {
        $id = $this->signIn($this->admin)->postJson($this->url('/identity'), ['type' => 'passport', 'country' => 'IN', 'number' => 'K1234567'])->json('uuid');
        $list = $this->getJson($this->url('/identity'))->assertOk();
        $this->assertSame('••••4567', $list->json('data.0.number_masked'));
        $this->assertStringNotContainsString('K1234567', $list->getContent());

        $this->postJson($this->url('/identity/'.$id.'/reveal'))->assertOk()->assertJsonPath('number', 'K1234567')->assertHeader('Cache-Control', 'no-store, private');
        $this->context($this->admin->tenant_id);
        $audit = AuditLog::where('action', 'revealed')->get();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString('K1234567', json_encode($audit));
        $this->assertSame('employee:'.$this->emp->uuid, $audit[0]->resource);

        // The employee sees the masked list of their own record but may not reveal.
        $this->as($this->emp)->getJson($this->url('/identity'))->assertOk()->assertJsonPath('data.0.number_masked', '••••4567');
        $this->postJson($this->url('/identity/'.$id.'/reveal'))->assertForbidden();
        // Managers have no access at all; others cannot even see the employee.
        $this->as($this->mgr)->getJson($this->url('/identity'))->assertForbidden();
        $this->postJson($this->url('/identity/'.$id.'/reveal'))->assertForbidden();
        $this->as($this->other)->getJson($this->url('/identity'))->assertNotFound();
        // A masked-only HR role (sensitive.view without reveal) sees masks, cannot reveal.
        $this->context($this->admin->tenant_id);
        $hr = $this->userWithRole('Team member', 'viewer@alpha.test');
        $role = \App\Models\Role::create(['name' => 'Sensitive viewer', 'description' => 'x', 'data_scope' => 'tenant', 'system' => false]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('key', ['people.view', 'people.sensitive.view'])->pluck('id')->all());
        $hr->roles()->sync([$role->id]);
        $this->signIn($hr)->getJson($this->url('/identity'))->assertOk()->assertJsonPath('data.0.number_masked', '••••4567');
        $this->postJson($this->url('/identity/'.$id.'/reveal'))->assertForbidden();
    }

    public function test_identity_update_verify_delete_and_self_service_rules(): void
    {
        $this->signIn($this->admin);
        $id = $this->postJson($this->url('/identity'), ['type' => 'passport', 'country' => 'IN', 'number' => 'K1234567'])->json('uuid');
        $this->postJson($this->url('/identity/'.$id.'/verify'), ['status' => 'verified'])->assertOk()->assertJsonPath('verification_status', 'verified');
        $this->patchJson($this->url('/identity/'.$id), ['expiry_date' => '2031-01-01'])->assertOk()->assertJsonPath('verification_status', 'verified');
        $this->patchJson($this->url('/identity/'.$id), ['number' => 'M7654321'])->assertOk()->assertJsonPath('number_masked', '••••4321')->assertJsonPath('verification_status', 'unverified');
        $this->patchJson($this->url('/identity/'.$id), ['type' => 'pan'])->assertStatus(422); // stored number no longer fits the new type
        $this->postJson($this->url('/identity/'.$id.'/reveal'))->assertJsonPath('number', 'M7654321');

        // Self service: default rule is request -> 409; the employee cannot verify their own record.
        $this->as($this->emp)->postJson($this->url('/identity'), ['type' => 'passport', 'country' => 'IN', 'number' => 'K7654321'])->assertStatus(409)->assertJsonPath('code', 'change_request_required')->assertJsonPath('section', 'identity');
        $this->postJson($this->url('/identity/'.$id.'/verify'), ['status' => 'verified'])->assertForbidden();
        $this->context($this->admin->tenant_id);
        PeopleSettings::save(['edit_rules' => ['identity' => 'direct']]);
        $this->as($this->emp)->postJson($this->url('/identity'), ['type' => 'national_id', 'country' => 'IN', 'number' => 'ID998877'])->assertCreated();
        $this->as($this->mgr)->postJson($this->url('/identity'), ['type' => 'national_id', 'country' => 'IN', 'number' => 'ID998877'])->assertForbidden();

        $this->signIn($this->admin)->deleteJson($this->url('/identity/'.$id))->assertNoContent();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, DB::table('identity_documents')->count());
        $this->assertTrue(DomainEvent::where('type', 'IdentityChanged')->where('classification', 'hr_confidential')->exists());
        $this->assertStringNotContainsString('M7654321', $this->dump('domain_events').$this->dump('audit_logs'));
    }

    public function test_blind_index_helper_supports_duplicate_detection_without_decrypting(): void
    {
        $a = IdentityHash::for('ab-12 34', 1);
        $this->assertSame($a, IdentityHash::for('AB1234', 1), 'normalized before hashing');
        $this->assertNotSame($a, IdentityHash::for('AB1234', 2), 'tenant-keyed');
        $this->assertSame(64, strlen($a));

        $this->signIn($this->admin);
        $this->postJson($this->url('/identity'), ['type' => 'passport', 'country' => 'IN', 'number' => 'K1234567'])->assertCreated();
        // The same passport on another person warns HR (409) and goes through when confirmed.
        $payload = ['type' => 'passport', 'country' => 'IN', 'number' => 'k1234567'];
        $this->postJson($this->url('/identity', $this->other), $payload)->assertStatus(409)->assertJsonPath('code', 'possible_duplicate');
        $this->postJson($this->url('/identity', $this->other), $payload + ['confirm_duplicate' => true])->assertCreated();
        $this->context($this->admin->tenant_id);
        $found = app(IdentityService::class)->findDuplicates('K 1234567', $this->emp->person_id);
        $this->assertSame([$this->other->person_id], $found->pluck('person_id')->all());
    }

    public function test_work_authorization_lifecycle_and_masking(): void
    {
        $this->signIn($this->admin);
        $res = $this->postJson($this->url('/work-authorizations'), ['type' => 'residence_visa', 'country' => 'AE', 'number' => 'RV123456', 'sponsor' => 'Alpha FZ LLC', 'issue_date' => '2025-01-01', 'expiry_date' => '2027-01-01'])->assertCreated();
        $res->assertJsonPath('number_masked', '••••3456')->assertJsonPath('status', 'active')->assertJsonMissingPath('number');
        $id = $res->json('uuid');
        $this->postJson($this->url('/work-authorizations'), ['type' => 'visa', 'country' => 'US'])->assertCreated()->assertJsonPath('number_masked', null);
        $this->postJson($this->url('/work-authorizations'), ['type' => 'ssn', 'country' => 'US'])->assertStatus(422);
        $this->patchJson($this->url('/work-authorizations/'.$id), ['expiry_date' => '2026-01-01'])->assertOk()->assertJsonPath('status', 'expired');
        $this->postJson($this->url('/work-authorizations/'.$id.'/verify'), ['status' => 'verified'])->assertOk();
        $this->postJson($this->url('/work-authorizations/'.$id.'/reveal'))->assertJsonPath('number', 'RV123456');
        $this->context($this->admin->tenant_id);
        $this->assertStringNotContainsString('RV123456', $this->dump('work_authorizations').$this->dump('audit_logs'));
        $this->as($this->mgr)->getJson($this->url('/work-authorizations'))->assertForbidden();
        $this->as($this->emp)->getJson($this->url('/work-authorizations'))->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson($this->url('/work-authorizations/'.$id))->assertStatus(409); // self rule is "request"
    }

    // ---- Bank ----------------------------------------------------------------------------------

    private function bank(array $over = []): array
    {
        return $over + ['account_holder' => 'Asha Rao', 'bank_name' => 'HDFC Bank', 'country' => 'IN', 'details' => ['ifsc' => 'HDFC0001234', 'account_number' => '1234 5678 9012']];
    }

    public function test_bank_details_use_the_agreed_encryption_format_and_are_never_plaintext_at_rest(): void
    {
        $this->signIn($this->admin);
        $res = $this->postJson($this->url('/bank-accounts'), $this->bank())->assertCreated();
        $res->assertJsonPath('account_last4', '9012')->assertJsonPath('is_primary', true)->assertJsonPath('currency', 'INR')->assertJsonPath('details.account_number', '••••9012');
        $this->assertStringNotContainsString('123456789012', $res->getContent());
        $this->assertStringNotContainsString('HDFC0001234', $res->getContent());

        $this->context($this->admin->tenant_id);
        $row = DB::table('employee_bank_accounts')->first();
        $this->assertEquals(['ifsc' => 'HDFC0001234', 'account_number' => '123456789012'], json_decode(Crypt::decryptString($row->details_encrypted), true));
        $this->assertStringNotContainsString('123456789012', $this->dump('employee_bank_accounts').$this->dump('audit_logs').$this->dump('domain_events'));
        $this->assertStringNotContainsString('HDFC0001234', $this->dump('employee_bank_accounts').$this->dump('audit_logs').$this->dump('domain_events'));
        $this->assertTrue(DomainEvent::where('type', 'BankAccountChanged')->where('classification', 'highly_sensitive')->exists());

        $this->signIn($this->admin);
        $id = $res->json('uuid');
        $this->postJson($this->url('/bank-accounts/'.$id.'/reveal'))->assertOk()->assertJsonPath('details.account_number', '123456789012');
        $this->context($this->admin->tenant_id);
        $this->assertTrue(AuditLog::where('action', 'revealed')->where('context->section', 'bank')->exists());
    }

    public function test_bank_schema_validation_per_country(): void
    {
        $this->signIn($this->admin);
        $uk = ['account_holder' => 'A', 'bank_name' => 'Barclays', 'country' => 'GB'];
        $this->postJson($this->url('/bank-accounts'), $uk + ['details' => ['sort_code' => '11-22-33', 'account_number' => '12345678']])->assertCreated()->assertJsonPath('currency', 'GBP');
        $this->postJson($this->url('/bank-accounts'), $uk + ['details' => ['sort_code' => '1122', 'account_number' => '12345678']])->assertStatus(422)->assertJsonValidationErrors('details.sort_code');
        $this->postJson($this->url('/bank-accounts'), $uk + ['details' => ['sort_code' => '112233']])->assertStatus(422)->assertJsonValidationErrors('details.account_number');
        $us = ['account_holder' => 'A', 'bank_name' => 'Chase', 'country' => 'US', 'details' => ['routing_number' => '021000021', 'account_number' => '000123456789', 'account_type' => 'checking']];
        $this->postJson($this->url('/bank-accounts'), $us)->assertCreated()->assertJsonPath('details.account_type', 'checking');
        $this->postJson($this->url('/bank-accounts'), ['details' => ['account_type' => 'gold'] + $us['details']] + $us)->assertStatus(422);
        $de = ['account_holder' => 'A', 'bank_name' => 'Deutsche', 'country' => 'DE', 'details' => ['iban' => 'DE89 3704 0044 0532 0130 00', 'bic' => 'COBADEFFXXX']];
        $this->postJson($this->url('/bank-accounts'), $de)->assertCreated()->assertJsonPath('account_last4', '3000');
        $this->postJson($this->url('/bank-accounts'), ['details' => ['iban' => 'DE89370400440532013001']] + $de)->assertStatus(422)->assertJsonValidationErrors('details.iban'); // fails mod-97
        $this->postJson($this->url('/bank-accounts'), ['country' => 'FR', 'details' => ['account_number' => '12345678', 'swift_bic' => 'BNPAFRPP']] + $de)->assertCreated(); // generic SWIFT schema
        $this->postJson($this->url('/bank-accounts'), ['country' => 'FR', 'details' => ['account_number' => '12345678']] + $de)->assertStatus(422)->assertJsonValidationErrors('details.swift_bic');
    }

    public function test_one_active_primary_retire_keeps_history_and_self_service_is_a_request(): void
    {
        $this->signIn($this->admin);
        $a = $this->postJson($this->url('/bank-accounts'), $this->bank())->json('uuid');
        $b = $this->postJson($this->url('/bank-accounts'), $this->bank(['bank_name' => 'ICICI', 'details' => ['ifsc' => 'ICIC0000001', 'account_number' => '998877665544'], 'is_primary' => true]))->json('uuid');
        $rows = collect($this->getJson($this->url('/bank-accounts'))->json('data'));
        $this->assertFalse($rows->firstWhere('uuid', $a)['is_primary']);
        $this->assertTrue($rows->firstWhere('uuid', $b)['is_primary']);
        $this->patchJson($this->url('/bank-accounts/'.$a), ['is_primary' => true])->assertOk();
        $this->assertSame(1, collect($this->getJson($this->url('/bank-accounts'))->json('data'))->where('is_primary', true)->count());

        $this->postJson($this->url('/bank-accounts/'.$a.'/retire'), [])->assertOk()->assertJsonPath('status', 'retired')->assertJsonPath('is_primary', false)->assertJsonPath('effective_to', '2026-10-07');
        $this->postJson($this->url('/bank-accounts/'.$a.'/retire'), [])->assertStatus(422);
        $this->patchJson($this->url('/bank-accounts/'.$a), ['bank_name' => 'X'])->assertStatus(422);
        $this->deleteJson($this->url('/bank-accounts/'.$a))->assertStatus(405);
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, DB::table('employee_bank_accounts')->count(), 'nothing is deleted');
        $this->signIn($this->admin);
        $this->patchJson($this->url('/bank-accounts/'.$b), ['details' => ['ifsc' => 'ICIC0000002', 'account_number' => '112233445566']])->assertOk()->assertJsonPath('account_last4', '5566');

        $this->as($this->emp)->postJson($this->url('/bank-accounts'), $this->bank())->assertStatus(409)->assertJsonPath('code', 'change_request_required')->assertJsonPath('section', 'bank');
        $this->postJson($this->url('/bank-accounts/'.$b.'/retire'), [])->assertStatus(409);
        $this->as($this->mgr)->postJson($this->url('/bank-accounts'), $this->bank())->assertForbidden();
    }

    public function test_every_non_self_bank_read_is_audited_and_access_is_restricted(): void
    {
        $id = $this->signIn($this->admin)->postJson($this->url('/bank-accounts'), $this->bank())->json('uuid');
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, AuditLog::where('action', 'bank_viewed')->count());
        $this->signIn($this->admin);
        $this->getJson($this->url('/bank-accounts'))->assertOk();
        $this->getJson($this->url('/bank-accounts/'.$id))->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, AuditLog::where('action', 'bank_viewed')->where('resource', 'employee:'.$this->emp->uuid)->count());

        $this->as($this->emp)->getJson($this->url('/bank-accounts'))->assertOk()->assertJsonPath('data.0.details.account_number', '••••9012');
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, AuditLog::where('action', 'bank_viewed')->count(), 'self reads are not access events');
        $this->as($this->emp)->postJson($this->url('/bank-accounts/'.$id.'/reveal'))->assertForbidden();
        $this->as($this->mgr)->getJson($this->url('/bank-accounts'))->assertForbidden();
        $this->as($this->other)->getJson($this->url('/bank-accounts/'.$id))->assertNotFound();
        $this->as($this->other)->getJson($this->url('/bank-accounts', $this->other))->assertOk()->assertJsonCount(0, 'data');
    }

    // ---- Expiry command ------------------------------------------------------------------------

    public function test_expiry_command_records_one_event_and_one_task_per_item_and_window(): void
    {
        $tenant = $this->admin->tenant_id;
        $this->signIn($this->admin);
        $doc = $this->post($this->url('/documents'), ['file' => RecordsTest::pdf(), 'document_type' => 'passport', 'expiry_date' => '2026-10-20'], ['Accept' => 'application/json'])->assertCreated()->json('uuid');
        $this->post($this->url('/documents'), ['file' => RecordsTest::pdf(), 'document_type' => 'passport', 'expiry_date' => '2027-06-01'], ['Accept' => 'application/json'])->assertCreated();
        $this->postJson($this->url('/identity'), ['type' => 'passport', 'country' => 'IN', 'number' => 'K1234567', 'expiry_date' => '2026-10-25'])->assertCreated();
        $this->postJson($this->url('/work-authorizations'), ['type' => 'visa', 'country' => 'US', 'expiry_date' => '2026-11-30'])->assertCreated(); // outside 30 days

        $this->assertSame(0, Artisan::call('people:document-expiry'));
        $this->context($tenant);
        $this->assertSame(2, DomainEvent::where('type', 'DocumentExpiring')->count());
        $event = DomainEvent::where('type', 'DocumentExpiring')->where('subject_type', 'document')->first();
        $this->assertSame($this->emp->id, $event->employee_id);
        $this->assertSame('hr_confidential', $event->classification);
        $this->assertSame(30, $event->payload['window_days']);
        $tasks = WorkItem::where('source_module', 'people')->get();
        $this->assertCount(2, $tasks);
        $this->assertSame('document:'.$doc, $tasks->firstWhere('source_entity', 'document:'.$doc)->source_entity);
        $this->assertSame('/people/'.$this->emp->uuid.'?tab=documents', $tasks->firstWhere('source_entity', 'document:'.$doc)->action);
        $this->assertSame($this->admin->id, $tasks->first()->assignee_id);

        Artisan::call('people:document-expiry');
        $this->context($tenant);
        $this->assertSame(2, DomainEvent::where('type', 'DocumentExpiring')->count(), 'idempotent per item and window');
        $this->assertSame(2, WorkItem::where('source_module', 'people')->count());

        // A wider window picks up the visa once (a different window is a different announcement).
        Artisan::call('people:document-expiry', ['--days' => 60]);
        $this->context($tenant);
        $this->assertSame(2 + 3, DomainEvent::where('type', 'DocumentExpiring')->count());
        Artisan::call('people:document-expiry', ['--days' => 60]);
        $this->context($tenant);
        $this->assertSame(5, DomainEvent::where('type', 'DocumentExpiring')->count());
    }

    public function test_expiry_window_comes_from_people_settings(): void
    {
        $tenant = $this->admin->tenant_id;
        $this->signIn($this->admin)->post($this->url('/documents'), ['file' => RecordsTest::pdf(), 'document_type' => 'passport', 'expiry_date' => '2026-12-15'], ['Accept' => 'application/json'])->assertCreated();
        Artisan::call('people:document-expiry');
        $this->context($tenant);
        $this->assertSame(0, DomainEvent::where('type', 'DocumentExpiring')->count());
        PeopleSettings::save(['document_expiry_days' => 90]);
        Artisan::call('people:document-expiry');
        $this->context($tenant);
        $this->assertSame(1, DomainEvent::where('type', 'DocumentExpiring')->count());
        $this->assertSame(90, DomainEvent::where('type', 'DocumentExpiring')->first()->payload['window_days']);
    }
}
