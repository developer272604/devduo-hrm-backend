<?php

namespace Tests\Feature\Security;

use App\Domain\Security\Models\PasswordHistory;
use App\Domain\Security\Models\UserSession;
use App\Domain\Security\SessionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\SecurityFixtures;
use Tests\TestCase;

class SessionPasswordTest extends TestCase
{
    use RefreshDatabase, SecurityFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenants();
    }

    private function otherSession(User $user, array $attributes = []): UserSession
    {
        $this->context($user->tenant_id);
        $s = UserSession::create($attributes + ['user_id' => $user->id, 'session_hash' => hash('sha256', bin2hex(random_bytes(8))), 'ip_address' => '10.0.0.9', 'device' => 'Firefox on Linux', 'last_seen_at' => now()]);
        $this->context(null);

        return $s;
    }

    public function test_sessions_are_listed_with_device_ip_and_current_marker_and_can_be_revoked(): void
    {
        $this->signIn($this->admin);
        $other = $this->otherSession($this->admin);
        $list = $this->getJson('/api/v1/security/sessions')->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertCount(1, array_filter($list, fn ($s) => $s['current']));
        $this->assertContains('Firefox on Linux', array_column($list, 'device'));
        $this->assertContains('10.0.0.9', array_column($list, 'ip_address'));

        $current = collect($list)->firstWhere('current', true)['uuid'];
        $this->deleteJson('/api/v1/security/sessions/'.$current)->assertStatus(422);
        $this->deleteJson('/api/v1/security/sessions/'.$other->uuid)->assertNoContent();
        $this->assertCount(1, $this->getJson('/api/v1/security/sessions')->json('data'));
        $this->deleteJson('/api/v1/security/sessions/'.$other->uuid)->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.session.revoked']);
    }

    public function test_revoke_others_keeps_the_current_session_and_a_revoked_session_is_refused(): void
    {
        $this->signIn($this->admin);
        $a = $this->otherSession($this->admin);
        $b = $this->otherSession($this->admin);
        $this->postJson('/api/v1/security/sessions/revoke-others')->assertOk()->assertJsonPath('revoked', 2);
        $this->assertNotNull($a->fresh()->revoked_at);
        $this->assertNotNull($b->fresh()->revoked_at);
        $this->getJson('/api/me')->assertOk();

        // Revoking the session that is making the request ends it on the next call.
        $this->context($this->admin->tenant_id);
        UserSession::where('user_id', $this->admin->id)->update(['revoked_at' => now()]);
        $this->context(null);
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_sessions_are_private_to_the_user_and_tenant(): void
    {
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $theirs = $this->otherSession($member);
        $foreign = $this->otherSession($this->other);
        $this->signIn($this->admin);
        $this->getJson('/api/v1/security/sessions')->assertOk();
        $this->deleteJson('/api/v1/security/sessions/'.$theirs->uuid)->assertNotFound();
        $this->deleteJson('/api/v1/security/sessions/'.$foreign->uuid)->assertNotFound();
        // Admin forced logout works inside the tenant only.
        $this->postJson('/api/v1/security/users/'.$member->id.'/sessions/revoke')->assertOk()->assertJsonPath('revoked', 1);
        $this->postJson('/api/v1/security/users/'.$this->other->id.'/sessions/revoke')->assertNotFound();
        $this->assertNull($foreign->fresh()->revoked_at);
        $this->signIn($member)->postJson('/api/v1/security/users/'.$this->admin->id.'/sessions/revoke')->assertForbidden();
    }

    public function test_password_change_signs_out_other_sessions_and_keeps_this_one(): void
    {
        $this->signIn($this->admin);
        $this->getJson('/api/v1/security/sessions')->assertOk();
        $other = $this->otherSession($this->admin);
        $this->postJson('/api/change-password', ['current_password' => 'StrongPassword123', 'password' => 'BrandNewPassw0rd9', 'password_confirmation' => 'BrandNewPassw0rd9'])->assertOk();
        $this->assertNotNull($other->fresh()->revoked_at);
        $this->assertSame('password_changed', $other->fresh()->revoke_reason);
        $this->resumeSession($this->admin);
        $this->getJson('/api/v1/security/sessions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_idle_timeout_ends_the_session(): void
    {
        $this->policy($this->admin, ['session' => ['idle_minutes' => 15]]);
        $this->signIn($this->admin);
        $this->getJson('/api/me')->assertOk();
        $this->context($this->admin->tenant_id);
        UserSession::where('user_id', $this->admin->id)->update(['last_seen_at' => now()->subMinutes(30)]);
        $this->context(null);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->context($this->admin->tenant_id);
        $this->assertSame('idle_timeout', UserSession::where('user_id', $this->admin->id)->value('revoke_reason'));
    }

    public function test_absolute_timeout_ends_the_session(): void
    {
        $this->policy($this->admin, ['session' => ['absolute_minutes' => 60]]);
        $this->signIn($this->admin);
        $this->getJson('/api/me')->assertOk();
        $this->context($this->admin->tenant_id);
        UserSession::where('user_id', $this->admin->id)->update(['created_at' => now()->subMinutes(90)]);
        $this->context(null);
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_concurrent_session_limit_signs_out_the_oldest_login(): void
    {
        $this->policy($this->admin, ['session' => ['max_concurrent' => 1]]);
        $login = ['workspace' => 'alpha', 'email' => 'admin@alpha.test', 'password' => 'StrongPassword123'];
        $this->postJson('/api/login', $login)->assertOk();
        $this->context($this->admin->tenant_id);
        $first = UserSession::where('user_id', $this->admin->id)->firstOrFail();
        $this->context(null);
        $this->postJson('/api/logout')->assertNoContent(); // a fresh browser logs in below
        $this->context($this->admin->tenant_id);
        $first->forceFill(['revoked_at' => null, 'revoke_reason' => null, 'last_seen_at' => now()->subMinute()])->save(); // pretend the first browser is still active
        $this->context(null);
        $this->postJson('/api/login', $login)->assertOk();
        $this->assertNotNull($first->fresh()->revoked_at);
        $this->assertSame('concurrent_limit', $first->fresh()->revoke_reason);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, UserSession::where('user_id', $this->admin->id)->whereNull('revoked_at')->count());
    }

    public function test_password_history_and_min_length_are_enforced_on_change(): void
    {
        $this->policy($this->admin, ['password' => ['min_length' => 14, 'history' => 2]]);
        $this->signIn($this->admin);
        $change = function (string $current, string $new) {
            \Illuminate\Support\Facades\Cache::flush(); // the route's throttle shares its key with the group throttle
            return $this->postJson('/api/change-password', ['current_password' => $current, 'password' => $new, 'password_confirmation' => $new]);
        };
        $change('StrongPassword123', 'Short1pass')->assertStatus(422);
        $change('StrongPassword123', 'SecondPassword456')->assertOk();
        $this->signIn($this->admin);
        $this->resumeSession($this->admin);
        $change('SecondPassword456', 'StrongPassword123')->assertStatus(422)->assertJsonValidationErrors('password'); // reuse of the previous password
        $this->resumeSession($this->admin);
        $change('SecondPassword456', 'ThirdPassword789x')->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, PasswordHistory::where('user_id', $this->admin->id)->count());
    }

    public function test_admin_created_users_follow_tenant_password_policy(): void
    {
        $this->policy($this->admin, ['password' => ['min_length' => 18]]);
        $this->signIn($this->admin);
        $this->context($this->admin->tenant_id);
        $role = \App\Models\Role::where('name', 'Team member')->firstOrFail();
        $this->context(null);
        $payload = ['name' => 'Policy User', 'email' => 'policy.user@alpha.test', 'active' => true, 'role_ids' => [$role->id]];

        $this->postJson('/api/users', $payload + ['password' => 'ShortPassword123'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $created = $this->postJson('/api/users', $payload + ['password' => 'LongEnoughPassword123'])->assertCreated()->json();
        $this->assertNotNull(User::findOrFail($created['id'])->password_changed_at);
    }

    public function test_password_history_is_enforced_on_reset(): void
    {
        $this->policy($this->admin, ['password' => ['history' => 3]]);
        Notification::fake();
        Password::sendResetLink(['email' => 'admin@alpha.test']);
        $token = null;
        Notification::assertSentTo($this->admin, \Illuminate\Auth\Notifications\ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $reset = fn (string $p) => $this->postJson('/api/reset-password', ['email' => 'admin@alpha.test', 'token' => $token, 'password' => $p, 'password_confirmation' => $p]);
        $reset('StrongPassword123')->assertStatus(422)->assertJsonValidationErrors('password');
        $reset('FreshResetPassw0rd')->assertOk(); // the token survived the rejected attempt
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, PasswordHistory::where('user_id', $this->admin->id)->count());
    }

    public function test_expired_password_blocks_the_api_until_changed(): void
    {
        $this->policy($this->admin, ['password' => ['expiry_days' => 30]]);
        \DB::table('users')->where('id', $this->admin->id)->update(['password_changed_at' => now()->subDays(45)]);
        $this->signIn($this->admin);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('security.password_expired', true);
        $this->getJson('/api/dashboard')->assertForbidden()->assertJsonPath('code', 'password_expired');
        $this->postJson('/api/change-password', ['current_password' => 'StrongPassword123', 'password' => 'RenewedPassword456', 'password_confirmation' => 'RenewedPassword456'])->assertOk();
        $this->resumeSession($this->admin);
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_lockout_after_threshold_blocks_even_the_correct_password(): void
    {
        $this->policy($this->admin, ['password' => ['lockout_attempts' => 3, 'lockout_minutes' => 10]]);
        $bad = ['workspace' => 'alpha', 'email' => 'admin@alpha.test', 'password' => 'incorrect'];
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/login', $bad)->assertStatus(422);
        }
        $good = ['workspace' => 'alpha', 'email' => 'admin@alpha.test', 'password' => 'StrongPassword123'];
        $this->postJson('/api/login', $good)->assertStatus(429);
        $this->assertDatabaseHas('audit_logs', ['action' => 'security.account_locked']);
        $this->travel(11)->minutes();
        \Illuminate\Support\Facades\RateLimiter::clear('login:'.hash('sha256', 'alpha|admin@alpha.test'));
        $this->postJson('/api/login', $good)->assertOk();
    }

    public function test_session_service_hashes_ids_and_never_stores_them(): void
    {
        $this->signIn($this->admin);
        $this->getJson('/api/me')->assertOk();
        $stored = \DB::table('user_sessions')->value('session_hash');
        $this->assertSame(64, strlen($stored));
        $this->assertSame(SessionService::hashId(app('session.store')->getId()), $stored);
    }
}
