<?php

namespace Tests\Feature\Integrations;

use Illuminate\Support\Facades\DB;

class ApiClientTest extends IntegrationsTestCase
{
    public function test_key_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $this->hire('Asha Rao');
        $res = $this->postJson(self::API.'api-clients', ['name' => 'Lake', 'scopes' => ['employees:read']])->assertCreated();
        $key = $res->json('key');
        $this->assertMatchesRegularExpression('/^dvk_[a-z0-9]{8}\.[A-Za-z0-9]{40}$/', $key);
        $row = DB::table('integration_api_clients')->first();
        $this->assertSame(hash('sha256', $key), $row->key_hash);
        $this->assertStringNotContainsString($key, json_encode($row));
        $list = $this->getJson(self::API.'api-clients')->assertOk();
        $this->assertStringNotContainsString($key, $list->getContent());
        $this->assertStringNotContainsString($row->key_hash, $list->getContent());
        $this->assertArrayNotHasKey('key', $list->json('data.0'));
    }

    public function test_scopes_are_enforced_and_unknown_scopes_rejected(): void
    {
        $this->hire('Asha Rao');
        [, $deptOnly] = $this->makeClient(['scopes' => ['departments:read']]);
        $this->postJson(self::API.'api-clients', ['name' => 'x', 'scopes' => ['employees:write']])->assertStatus(422);
        $this->pub($deptOnly, '/api/public/v1/employees')->assertForbidden()->assertJsonPath('required_scope', 'employees:read');
        $this->pub($deptOnly, '/api/public/v1/departments')->assertOk()->assertJsonStructure(['data' => [['code', 'name', 'active']], 'meta']);
        $this->pub(null, '/api/public/v1/departments')->assertUnauthorized();
        $this->pub('dvk_abcdefgh.'.str_repeat('a', 40), '/api/public/v1/departments')->assertUnauthorized();
    }

    public function test_field_privacy_only_minimal_fields_and_email_needs_its_own_scope(): void
    {
        $this->hire('Asha Rao', ['personal_email' => 'asha.private@example.com', 'dob' => '1990-02-03']);
        [, $key] = $this->makeClient();
        $res = $this->pub($key, '/api/public/v1/employees')->assertOk();
        $this->assertSame(['department_code', 'display_name', 'employee_number', 'id', 'joined_on', 'status'], collect($res->json('data.0'))->keys()->sort()->values()->all());
        foreach (['asha.private@example.com', '1990-02-03', 'work_email', 'date_of_birth', 'personal'] as $needle) {
            $this->assertStringNotContainsString($needle, $res->getContent());
        }
        $this->assertSame('ENG', $res->json('data.0.department_code'));
        [, $emailKey] = $this->makeClient(['scopes' => ['employees:read', 'employees:email']]);
        $this->assertNotEmpty($this->pub($emailKey, '/api/public/v1/employees')->json('data.0.work_email'));
    }

    public function test_rate_limit_returns_429_and_requests_are_audited(): void
    {
        [$uuid, $key] = $this->makeClient(['rate_limit_per_minute' => 2]);
        $this->pub($key, '/api/public/v1/departments')->assertOk()->assertHeader('X-RateLimit-Remaining', '1');
        $this->pub($key, '/api/public/v1/departments')->assertOk();
        $this->pub($key, '/api/public/v1/departments')->assertStatus(429)->assertHeader('Retry-After');
        $this->signIn($this->admin);
        $log = $this->getJson(self::API."api-clients/$uuid/requests")->assertOk()->json('data');
        $this->assertSame([429, 200, 200], array_column($log, 'status'));
        $this->assertSame('/api/public/v1/departments', $log[0]['path']);
    }

    public function test_rotation_revocation_and_grace_window(): void
    {
        [$uuid, $old] = $this->makeClient();
        $new = $this->postJson(self::API."api-clients/$uuid/rotate")->assertOk()->json('key');
        $this->assertNotSame($old, $new);
        $this->pub($old, '/api/public/v1/departments')->assertUnauthorized();
        $this->pub($new, '/api/public/v1/departments')->assertOk();

        $graceNew = $this->postJson(self::API."api-clients/$uuid/rotate", ['grace_minutes' => 10])->assertOk()->json('key');
        $this->pub($new, '/api/public/v1/departments')->assertOk();
        $this->travel(11)->minutes();
        $this->pub($new, '/api/public/v1/departments')->assertUnauthorized();
        $this->pub($graceNew, '/api/public/v1/departments')->assertOk();

        $this->signIn($this->admin);
        $this->postJson(self::API."api-clients/$uuid/revoke")->assertOk()->assertJsonPath('status', 'revoked');
        $this->pub($graceNew, '/api/public/v1/departments')->assertUnauthorized();
        $this->postJson(self::API."api-clients/$uuid/rotate")->assertStatus(409);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'integration.api_client.revoked')->exists());
    }

    public function test_ip_allow_list_supports_addresses_and_cidr(): void
    {
        [, $blocked] = $this->makeClient(['ip_allowlist' => ['203.0.113.7']]);
        [, $cidr] = $this->makeClient(['ip_allowlist' => ['10.1.0.0/16', '127.0.0.0/8']]);
        $this->pub($blocked, '/api/public/v1/departments')->assertForbidden();
        $this->pub($cidr, '/api/public/v1/departments')->assertOk();
        $this->pub($blocked, '/api/public/v1/departments', ['REMOTE_ADDR' => '203.0.113.7'])->assertOk();
        $this->signIn($this->admin);
        $this->postJson(self::API.'api-clients', ['name' => 'bad', 'scopes' => ['employees:read'], 'ip_allowlist' => ['999.1.1.1']])->assertStatus(422);
    }

    public function test_tenant_isolation_and_permission_checks(): void
    {
        $asha = $this->hire('Asha Rao');
        [$uuid, $key] = $this->makeClient();
        $alphaTenant = $this->admin->tenant_id;
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->hire('Bela Shah');
        [, $betaKey] = $this->makeClient();
        $this->assertNotSame($alphaTenant, $this->admin->tenant_id);
        $this->postJson(self::API."api-clients/$uuid/revoke")->assertNotFound();
        $this->assertSame([], collect($this->getJson(self::API.'api-clients')->json('data'))->where('uuid', $uuid)->all());

        $alpha = $this->pub($key, '/api/public/v1/employees')->assertOk()->json('data');
        $beta = $this->pub($betaKey, '/api/public/v1/employees')->assertOk()->json('data');
        $this->assertSame([$asha->employee_number], array_column($alpha, 'employee_number'));
        $this->assertCount(1, $beta);
        $this->assertNotSame($alpha[0]['id'], $beta[0]['id']);

        $this->context($this->admin->tenant_id);
        $member = $this->userWithRole('Team member', 'tm@beta.test');
        $this->signIn($member);
        $this->getJson(self::API.'api-clients')->assertForbidden();
        $this->postJson(self::API.'api-clients', ['name' => 'x', 'scopes' => ['employees:read']])->assertForbidden();
    }
}
