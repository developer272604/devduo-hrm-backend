<?php

namespace Tests\Feature\Security;

use App\Domain\Audit\Audit;
use App\Domain\Audit\AuditChain;
use App\Domain\Lifecycle\LifecycleJobs;
use App\Domain\Security\LegalHoldService;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SecurityFixtures;
use Tests\TestCase;

class AuditHoldSettingsTest extends TestCase
{
    use RefreshDatabase, SecurityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenants();
    }

    private function memberRoleId(): int
    {
        $this->context($this->admin->tenant_id);
        $id = \App\Models\Role::where('name', 'Team member')->value('id');
        $this->context(null);

        return $id;
    }

    private function seedAudit(int $tenantId, int $count = 4): void
    {
        $this->context($tenantId);
        for ($i = 1; $i <= $count; $i++) {
            Audit::record('test.event', 'security', 'thing:'.$i, ['n' => $i, 'z' => ['b' => 2, 'a' => 1], 'text' => 'ünï/code']);
        }
    }

    public function test_chain_is_gapless_per_tenant_and_verifies(): void
    {
        $this->seedAudit($this->admin->tenant_id, 3);
        $this->seedAudit($this->other->tenant_id, 2);
        $this->context($this->admin->tenant_id);
        $rows = AuditLog::whereNotNull('sequence')->orderBy('sequence')->get();
        $this->assertSame(range(1, $rows->count()), $rows->pluck('sequence')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(AuditChain::GENESIS, $rows->first()->prev_hash);
        $this->assertSame($rows[0]->row_hash, $rows[1]->prev_hash);
        $result = AuditChain::verify();
        $this->assertTrue($result['ok']);
        $this->assertSame($rows->count(), $result['checked']);
        $this->context($this->other->tenant_id);
        $this->assertSame(1, AuditLog::whereNotNull('sequence')->min('sequence'));
        $this->assertTrue(AuditChain::verify()['ok']);
    }

    public function test_modified_row_is_detected(): void
    {
        $this->seedAudit($this->admin->tenant_id);
        DB::table('audit_logs')->where('tenant_id', $this->admin->tenant_id)->where('sequence', 2)->update(['action' => 'test.tampered']);
        $result = AuditChain::verify();
        $this->assertFalse($result['ok']);
        $this->assertSame([['sequence' => 2, 'reason' => 'row_modified']], $result['failures']);
    }

    public function test_context_edit_deleted_row_and_truncated_tail_are_detected(): void
    {
        $this->seedAudit($this->admin->tenant_id, 5);
        DB::table('audit_logs')->where('tenant_id', $this->admin->tenant_id)->where('sequence', 3)->update(['context' => json_encode(['n' => 99])]);
        $this->assertContains('row_modified', array_column(AuditChain::verify()['failures'], 'reason'));

        DB::table('audit_logs')->where('tenant_id', $this->admin->tenant_id)->where('sequence', 2)->delete();
        $reasons = array_column(AuditChain::verify()['failures'], 'reason');
        $this->assertContains('missing_rows', $reasons);
        $this->assertContains('chain_break', $reasons);

        $last = (int) DB::table('audit_logs')->where('tenant_id', $this->admin->tenant_id)->max('sequence');
        DB::table('audit_logs')->where('tenant_id', $this->admin->tenant_id)->where('sequence', $last)->delete();
        $this->assertContains('tail_truncated_or_altered', array_column(AuditChain::verify()['failures'], 'reason'));
    }

    public function test_tampering_in_one_tenant_does_not_affect_another(): void
    {
        $this->seedAudit($this->admin->tenant_id);
        $this->seedAudit($this->other->tenant_id);
        DB::table('audit_logs')->where('tenant_id', $this->admin->tenant_id)->where('sequence', 1)->update(['actor' => 'Mallory']);
        $this->context($this->other->tenant_id);
        $this->assertTrue(AuditChain::verify()['ok']);
        $this->context($this->admin->tenant_id);
        $this->assertFalse(AuditChain::verify()['ok']);
    }

    public function test_verify_endpoint_and_command(): void
    {
        $this->signIn($this->admin);
        $this->postJson('/api/v1/security/audit/verify')->assertOk()->assertJsonPath('ok', true);
        $this->artisan('security:audit-verify')->assertSuccessful();
        DB::table('audit_logs')->where('tenant_id', $this->admin->tenant_id)->where('sequence', 1)->update(['actor' => 'Mallory']);
        $this->postJson('/api/v1/security/audit/verify')->assertOk()->assertJsonPath('ok', false)->assertJsonPath('failures.0.reason', 'row_modified');
        $this->artisan('security:audit-verify', ['--tenant' => $this->admin->tenant_id])->assertFailed();
        $this->artisan('security:audit-verify', ['--tenant' => $this->other->tenant_id])->assertSuccessful();
    }

    public function test_audit_search_is_scoped_to_the_tenant_and_filterable(): void
    {
        $this->seedAudit($this->other->tenant_id, 2);
        $this->seedAudit($this->admin->tenant_id, 3);
        $this->context(null);
        $this->signIn($this->admin);
        $res = $this->getJson('/api/v1/security/audit?action=test.event&per_page=50')->assertOk();
        $this->assertSame(3, $res->json('total'));
        $this->assertNotNull($res->json('data.0.sequence'));
        $this->getJson('/api/v1/security/audit?from=2000-01-01&to=1999-01-01')->assertStatus(422);
    }

    public function test_export_requires_purpose_is_audited_and_neutralises_formulas(): void
    {
        $this->context($this->admin->tenant_id);
        Audit::record('test.formula', 'security', '=HYPERLINK("x")');
        $this->context(null);
        $this->signIn($this->admin);
        $this->get('/api/v1/security/audit/export', ['Accept' => 'text/csv'])->assertStatus(422);
        $this->get('/api/v1/security/audit/export?purpose=short', ['Accept' => 'application/json'])->assertStatus(422);
        $response = $this->get('/api/v1/security/audit/export?purpose='.rawurlencode('Quarterly SOX evidence for external auditor').'&action=test.formula');
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('sequence,time,actor', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->context($this->admin->tenant_id);
        $row = AuditLog::where('action', 'security.audit.exported')->latest('id')->firstOrFail();
        $this->assertSame('Quarterly SOX evidence for external auditor', $row->context['purpose']);
    }

    public function test_security_endpoints_enforce_permissions(): void
    {
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $hr = $this->userWithRole('HR administrator', 'hr@alpha.test');
        $routes = [['GET', 'settings'], ['PUT', 'settings'], ['GET', 'audit'], ['POST', 'audit/verify'], ['GET', 'audit/export?purpose=long+enough+purpose'], ['GET', 'legal-holds'], ['POST', 'legal-holds'], ['GET', 'retention-policies'], ['POST', 'retention-policies']];
        foreach ([$member, $hr] as $user) {
            $this->signIn($user);
            foreach ($routes as [$method, $path]) {
                $this->json($method, '/api/v1/security/'.$path)->assertForbidden();
            }
        }
        $this->signIn($this->admin);
        $this->getJson('/api/v1/security/settings')->assertOk();
        $this->getJson('/api/v1/security/audit')->assertOk();
        $this->getJson('/api/v1/security/legal-holds')->assertOk();
    }

    public function test_settings_validate_persist_audit_and_are_tenant_scoped(): void
    {
        $this->signIn($this->admin);
        $this->putJson('/api/v1/security/settings', ['password' => ['min_length' => 3]])->assertStatus(422);
        $this->putJson('/api/v1/security/settings', ['mfa' => ['enforcement' => 'roles', 'role_ids' => []]])->assertStatus(422);
        $this->putJson('/api/v1/security/settings', ['mfa' => ['enforcement' => 'roles', 'role_ids' => [$this->memberRoleId()]], 'session' => ['idle_minutes' => 20, 'max_concurrent' => 3], 'password' => ['history' => 5, 'expiry_days' => 90], 'audit' => ['retention_days' => 1000]])
            ->assertOk()->assertJsonPath('settings.mfa.enforcement', 'roles')->assertJsonPath('settings.session.idle_minutes', 20)->assertJsonPath('settings.password.history', 5);
        $this->putJson('/api/v1/security/settings', ['audit' => ['retention_days' => 30]])->assertStatus(422);
        $audit = AuditLog::where('action', 'security.settings.updated')->latest('id')->first();
        $this->assertNull($audit); // read through the tenant context below
        $this->context($this->admin->tenant_id);
        $audit = AuditLog::where('action', 'security.settings.updated')->latest('id')->firstOrFail();
        $this->assertSame('roles', $audit->context['changes']['mfa.enforcement']['to']);
        $this->signIn($this->other)->getJson('/api/v1/security/settings')->assertOk()->assertJsonPath('settings.mfa.enforcement', 'off')->assertJsonPath('settings.session.max_concurrent', 0);
    }

    public function test_sso_is_settings_only_and_cannot_be_enabled(): void
    {
        $this->signIn($this->admin);
        $this->putJson('/api/v1/security/settings', ['sso' => ['protocol' => 'oidc', 'enabled' => true, 'issuer' => 'https://idp.example.test']])->assertStatus(422);
        $this->putJson('/api/v1/security/settings', ['sso' => ['protocol' => 'oidc', 'issuer' => 'https://idp.example.test', 'client_id' => 'abc']])->assertOk()
            ->assertJsonPath('settings.sso.protocol', 'oidc')->assertJsonPath('settings.sso.enabled', false)->assertJsonPath('sso_adapter.live', false);
        $this->expectException(\RuntimeException::class);
        (new \App\Domain\Security\UnconfiguredSsoAdapter)->authorizationRequest('alpha', 'state');
    }

    public function test_legal_hold_lifecycle_scope_and_tenant_isolation(): void
    {
        $this->signIn($this->admin);
        $this->postJson('/api/v1/security/legal-holds', ['name' => 'Case 1', 'reason' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/security/legal-holds', ['name' => 'Case 1', 'reason' => 'Pending litigation', 'subject_ref' => 'candidate:abc'])->assertStatus(422);
        $recruitment = $this->postJson('/api/v1/security/legal-holds', ['name' => 'Recruitment dispute', 'reason' => 'Pending litigation', 'module' => 'recruitment'])->assertCreated()->json('uuid');
        $record = $this->postJson('/api/v1/security/legal-holds', ['name' => 'One candidate', 'reason' => 'Regulator request', 'module' => 'people', 'subject_ref' => 'employee:e1'])->assertCreated()->json('uuid');

        $this->context($this->admin->tenant_id);
        $this->assertTrue(LegalHoldService::held('recruitment'));
        $this->assertTrue(LegalHoldService::held('recruitment', 'candidate:z'));
        $this->assertFalse(LegalHoldService::held('payroll'));
        $this->assertFalse(LegalHoldService::held('people'), 'a record-level hold does not block module-wide batch jobs');
        $this->assertTrue(LegalHoldService::held('people', 'employee:e1'));
        $this->assertFalse(LegalHoldService::held('people', 'employee:e2'));
        $this->context($this->other->tenant_id);
        $this->assertFalse(LegalHoldService::held('recruitment'), 'holds are tenant scoped');
        $this->context(null);

        $this->signIn($this->other)->postJson('/api/v1/security/legal-holds/'.$recruitment.'/release', ['reason' => 'Not allowed'])->assertNotFound();
        $this->signIn($this->admin)->postJson('/api/v1/security/legal-holds/'.$recruitment.'/release', [])->assertStatus(422);
        $this->postJson('/api/v1/security/legal-holds/'.$recruitment.'/release', ['reason' => 'Matter settled'])->assertOk()->assertJsonPath('active', false);
        $this->postJson('/api/v1/security/legal-holds/'.$recruitment.'/release', ['reason' => 'Matter settled'])->assertStatus(409);
        $this->context($this->admin->tenant_id);
        $this->assertFalse(LegalHoldService::held('recruitment'));
        $this->assertTrue(AuditLog::where('action', 'security.legal_hold.placed')->exists());
        $this->assertTrue(AuditLog::where('action', 'security.legal_hold.released')->exists());
        $this->assertNotNull($record);
    }

    public function test_lifecycle_exit_data_purge_honours_a_legal_hold(): void
    {
        $tenant = $this->admin->tenant_id;
        DB::statement('PRAGMA defer_foreign_keys = ON'); // inside the test transaction: never committed, never checked
        DB::table('lifecycle_exit_interviews')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant, 'case_id' => 9001, 'employee_id' => 9001, 'answers' => 'secret',
            'status' => 'submitted', 'retention_until' => now()->subDay()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($this->admin);
        $uuid = $this->postJson('/api/v1/security/legal-holds', ['name' => 'Exit data', 'reason' => 'Employment tribunal', 'module' => 'lifecycle'])->assertCreated()->json('uuid');
        $this->context($tenant);
        $this->assertSame(0, app(LifecycleJobs::class)->housekeeping()['exit_data_purged']);
        $this->assertSame('submitted', DB::table('lifecycle_exit_interviews')->value('status'));
        $this->context(null);
        $this->postJson('/api/v1/security/legal-holds/'.$uuid.'/release', ['reason' => 'Tribunal concluded'])->assertOk();
        $this->context($tenant);
        $this->assertSame(1, app(LifecycleJobs::class)->housekeeping()['exit_data_purged']);
    }

    public function test_retention_registry_crud_and_isolation(): void
    {
        $this->signIn($this->admin);
        $body = ['data_category' => 'Exit interviews', 'module' => 'lifecycle', 'retention_days' => 730, 'action' => 'purge', 'enforced_by' => 'lifecycle:housekeeping', 'notes' => 'Configurable; confirm with counsel.'];
        $uuid = $this->postJson('/api/v1/security/retention-policies', $body)->assertCreated()->json('uuid');
        $this->postJson('/api/v1/security/retention-policies', $body)->assertStatus(422);
        $this->putJson('/api/v1/security/retention-policies/'.$uuid, ['retention_days' => 365] + $body)->assertOk()->assertJsonPath('retention_days', 365);
        $this->getJson('/api/v1/security/retention-policies')->assertOk()->assertJsonCount(1, 'data');
        $this->signIn($this->other)->getJson('/api/v1/security/retention-policies')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/security/retention-policies/'.$uuid, $body)->assertNotFound();
    }
}
