<?php

namespace Tests\Feature\Security;

use App\Domain\Security\Models\MfaRecoveryCode;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SecurityFixtures;
use Tests\TestCase;

class MfaTest extends TestCase
{
    use RefreshDatabase, SecurityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenants();
    }

    private function loginPassword(string $email = 'admin@alpha.test')
    {
        return $this->postJson('/api/login', ['workspace' => 'alpha', 'email' => $email, 'password' => 'StrongPassword123']);
    }

    public function test_enrolment_returns_secret_and_otpauth_uri_then_confirms_with_recovery_codes(): void
    {
        $this->signIn($this->admin);
        $setup = $this->postJson('/api/v1/security/mfa/enroll')->assertCreated()->json();
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_uri']);
        $this->assertStringContainsString('secret='.$setup['secret'], $setup['otpauth_uri']);
        $this->getJson('/api/v1/security/mfa')->assertJsonPath('pending_enrollment', true)->assertJsonPath('enabled', false);
        $this->postJson('/api/v1/security/mfa/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->postJson('/api/v1/security/mfa/confirm', ['code' => $this->totp($setup['secret'])])->assertOk()->json('recovery_codes');
        $this->assertCount(10, $codes);
        $this->getJson('/api/v1/security/mfa')->assertJsonPath('enabled', true)->assertJsonPath('recovery_codes_remaining', 10);
        $this->postJson('/api/v1/security/mfa/enroll')->assertStatus(409);
        // Secrets are encrypted and recovery codes are stored hashed.
        $this->assertStringNotContainsString($setup['secret'], \DB::table('user_mfa_factors')->value('secret'));
        $this->assertStringNotContainsString(str_replace('-', '', $codes[0]), implode(',', \DB::table('user_mfa_recovery_codes')->pluck('code_hash')->all()));
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.mfa.enabled']);
    }

    public function test_login_requires_second_step_and_rejects_replay_of_the_same_code(): void
    {
        [$secret] = $this->enrol($this->admin);
        $this->loginPassword()->assertOk()->assertJsonPath('mfa_required', true);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login/mfa', ['code' => '123456'])->assertStatus(422);
        $this->postJson('/api/login/mfa', ['code' => $this->totp($secret)])->assertOk()->assertJsonPath('tenant.slug', 'alpha')->assertJsonPath('security.mfa_enabled', true);
        $this->getJson('/api/me')->assertOk();
        $this->postJson('/api/logout')->assertNoContent();

        // The same time step must not be accepted a second time; the next step is.
        $this->loginPassword()->assertJsonPath('mfa_required', true);
        $this->postJson('/api/login/mfa', ['code' => $this->totp($secret)])->assertStatus(422);
        $this->postJson('/api/login/mfa', ['code' => $this->totp($secret, 1)])->assertOk();
    }

    public function test_challenge_without_a_password_step_is_rejected(): void
    {
        $this->enrol($this->admin);
        $this->postJson('/api/login/mfa', ['code' => '123456'])->assertStatus(422)->assertJsonPath('code', 'mfa_expired');
    }

    public function test_recovery_code_works_exactly_once(): void
    {
        [, $codes] = $this->enrol($this->admin);
        $this->loginPassword()->assertJsonPath('mfa_required', true);
        $this->postJson('/api/login/mfa', ['code' => strtoupper($codes[0])])->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->loginPassword();
        $this->postJson('/api/login/mfa', ['code' => $codes[0]])->assertStatus(422);
        $this->postJson('/api/login/mfa', ['code' => $codes[1]])->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(8, MfaRecoveryCode::whereNull('used_at')->count());
        $this->assertTrue(AuditLog::where('action', 'security.mfa.recovery_code_used')->exists());
    }

    public function test_wrong_codes_are_rate_limited(): void
    {
        $this->signIn($this->admin);
        $this->postJson('/api/v1/security/mfa/enroll')->assertCreated();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/security/mfa/confirm', ['code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/v1/security/mfa/confirm', ['code' => '000000'])->assertStatus(429);
    }

    public function test_pending_login_is_abandoned_after_too_many_wrong_codes(): void
    {
        [$secret] = $this->enrol($this->admin);
        $this->loginPassword();
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/login/mfa', ['code' => '111111'])->assertStatus(422);
        }
        $this->postJson('/api/login/mfa', ['code' => '111111'])->assertStatus(422)->assertJsonPath('code', 'mfa_expired');
        $this->postJson('/api/login/mfa', ['code' => $this->totp($secret)])->assertStatus(422);
    }

    public function test_enforcement_policy_limits_unenrolled_users_to_enrolment_routes(): void
    {
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $this->policy($this->admin, ['mfa' => ['enforcement' => 'roles', 'role_ids' => [$this->roleId('Team member')]]]);

        $this->signIn($this->admin);
        $this->getJson('/api/v1/security/sessions')->assertOk(); // admin role is not targeted

        $this->signIn($member);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('security.mfa_enrollment_required', true);
        $this->getJson('/api/dashboard')->assertForbidden()->assertJsonPath('code', 'mfa_enrollment_required');
        $secret = $this->postJson('/api/v1/security/mfa/enroll')->assertCreated()->json('secret');
        $this->postJson('/api/v1/security/mfa/confirm', ['code' => $this->totp($secret)])->assertOk();
        $this->getJson('/api/dashboard')->assertOk();
        // A required user cannot switch MFA off again.
        $this->postJson('/api/v1/security/mfa/disable', ['password' => 'StrongPassword123', 'code' => $this->totp($secret, 1)])->assertForbidden();
    }

    public function test_user_can_disable_when_not_required_and_admin_can_reset_a_lost_device(): void
    {
        [$secret] = $this->enrol($this->admin);
        $this->signIn($this->admin);
        $this->postJson('/api/v1/security/mfa/disable', ['password' => 'wrong-password', 'code' => $this->totp($secret, 1)])->assertStatus(422);
        $this->postJson('/api/v1/security/mfa/disable', ['password' => 'StrongPassword123', 'code' => $this->totp($secret, 1)])->assertNoContent();
        $this->getJson('/api/v1/security/mfa')->assertJsonPath('enabled', false);

        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $this->enrol($member);
        $this->deleteJson('/api/v1/security/users/'.$member->id.'/mfa')->assertNoContent();
        $this->context($this->admin->tenant_id);
        $this->assertFalse(app(\App\Domain\Security\MfaService::class)->isEnrolled($member));
        $this->assertTrue(AuditLog::where('action', 'security.mfa.reset_by_admin')->exists());
        $this->context(null);

        // Members cannot reset anyone; other tenants' users are 404.
        $this->signIn($member)->deleteJson('/api/v1/security/users/'.$this->admin->id.'/mfa')->assertForbidden();
        $this->signIn($this->admin)->deleteJson('/api/v1/security/users/'.$this->other->id.'/mfa')->assertNotFound();
    }

    public function test_recovery_codes_can_be_regenerated_and_old_ones_stop_working(): void
    {
        [$secret, $codes] = $this->enrol($this->admin);
        $this->signIn($this->admin);
        $new = $this->postJson('/api/v1/security/mfa/recovery-codes', ['code' => $this->totp($secret, 1)])->assertOk()->json('recovery_codes');
        $this->assertCount(10, $new);
        $this->assertNotContains($codes[0], $new);
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, MfaRecoveryCode::whereIn('code_hash', [hash_hmac('sha256', str_replace('-', '', $codes[0]), 'mfa-recovery|'.config('app.key'))])->count());
    }

    private function roleId(string $name): int
    {
        $this->context($this->admin->tenant_id);
        $id = \App\Models\Role::where('name', $name)->value('id');
        $this->context(null);

        return $id;
    }
}
