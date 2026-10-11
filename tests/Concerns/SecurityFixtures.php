<?php

namespace Tests\Concerns;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Security\MfaService;
use App\Domain\Security\SecurityPolicy;
use App\Domain\Security\Totp;
use App\Domain\Tenancy\TenantContext;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

trait SecurityFixtures
{
    protected User $admin;

    protected User $other;

    protected function bootTenants(): void
    {
        $this->admin = app(WorkspaceProvisioner::class)->create('Alpha', 'alpha', 'admin@alpha.test', 'StrongPassword123');
        $this->other = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        // The test client sends no cookies, so pin the session id to make the session register observable across requests.
        $this->withCredentials()->withCookie(config('session.cookie'), str_repeat('a', 40));
    }

    protected function context(?int $tenantId): void
    {
        app(TenantContext::class)->id = $tenantId;
    }

    protected function signIn(User $u): static
    {
        $u->refresh();
        $this->actingAs($u)->withSession(['version' => $u->session_version]);

        return $this;
    }

    /** After a request that rotated the session id (password change), continue with the browser's new cookie. */
    protected function resumeSession(User $u): static
    {
        $this->withCookie(config('session.cookie'), app('session.store')->getId());

        return $this->signIn($u);
    }

    protected function userWithRole(string $role, string $email, int $tenantId = null): User
    {
        $tenantId ??= $this->admin->tenant_id;
        $this->context($tenantId);
        $u = new User(['name' => ucfirst($role), 'email' => $email, 'password' => 'StrongPassword123']);
        $u->tenant_id = $tenantId;
        $u->save();
        $u->roles()->attach(Role::where('name', $role)->firstOrFail());
        $this->context(null);

        return $u;
    }

    protected function policy(User $tenantUser, array $override): void
    {
        $policy = array_replace_recursive(SecurityPolicy::for($tenantUser->tenant_id), $override);
        SecurityPolicy::save($tenantUser->tenant_id, $policy, null);
    }

    /** Enrol a user directly through the service; returns [secret, recovery codes]. */
    protected function enrol(User $user): array
    {
        $this->context($user->tenant_id);
        $service = app(MfaService::class);
        $setup = $service->beginEnrollment($user->fresh());
        $codes = $service->confirm($user->fresh(), Totp::code(Totp::base32Decode($setup['secret']), time() - 30)); // one step back so the current code stays usable
        $this->context(null);

        return [$setup['secret'], $codes];
    }

    protected function totp(string $secret, int $offsetSteps = 0): string
    {
        return Totp::code(Totp::base32Decode($secret), time() + 30 * $offsetSteps);
    }

    protected function secretOf(User $user): string
    {
        $this->context($user->tenant_id);
        $secret = Crypt::decryptString(app(MfaService::class)->factor($user)->secret);
        $this->context(null);

        return $secret;
    }
}
