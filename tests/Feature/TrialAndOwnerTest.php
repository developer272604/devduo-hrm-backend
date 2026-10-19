<?php

namespace Tests\Feature;

use App\Domain\Access\WorkspaceProvisioner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrialAndOwnerTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'StrongPassword123';

    private function register(array $over = [])
    {
        return $this->postJson('/api/register', $over + ['company_name' => 'Acme Corp', 'name' => 'Ada Admin', 'email' => 'ada@acme.test', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    private function owner(): User
    {
        $u = app(WorkspaceProvisioner::class)->create('HRMS Platform', 'platform', 'owner@platform.test', self::PASSWORD);
        $u->forceFill(['is_owner' => true])->save();
        $u->tenant->forceFill(['is_platform' => true])->save();

        return $u->refresh();
    }

    private function actAs(User $u): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($u)->withSession(['version' => $u->session_version]);
    }

    public function test_registration_starts_a_24_hour_trial_with_full_access(): void
    {
        $res = $this->register()->assertOk()->assertJsonPath('account.status', 'trial')->assertJsonPath('account.access', true);
        $tenant = Tenant::where('slug', 'acme-corp')->firstOrFail();
        $this->assertEquals(24, (int) round($tenant->trial_started_at->diffInHours($tenant->trial_ends_at)));
        $this->assertContains('users.manage', $res->json('permissions'));
        $this->getJson('/api/dashboard')->assertOk();
    }

    public function test_registration_validates_and_rejects_duplicate_email(): void
    {
        $this->register()->assertOk();
        $this->register(['company_name' => 'Other'])->assertStatus(422)->assertJsonValidationErrors('email');
        $this->register(['email' => 'x@y.test', 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_expired_trial_is_suspended_until_the_owner_approves(): void
    {
        $this->register()->assertOk();
        $tenant = Tenant::where('slug', 'acme-corp')->firstOrFail();
        $this->travel(25)->hours();
        $this->getJson('/api/dashboard')->assertStatus(403)->assertJsonPath('code', 'account_suspended');
        $this->getJson('/api/me')->assertOk()->assertJsonPath('account.status', 'suspended')->assertJsonPath('account.reason', 'trial_expired');
        $this->assertSame('suspended', $tenant->fresh()->status);
        $this->travelBack();

        $owner = $this->owner();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actAs($owner);
        $this->getJson('/api/owner/overview')->assertOk()->assertJsonPath('counts.awaiting_approval', 1);
        $this->postJson("/api/owner/companies/{$tenant->id}/approve", ['note' => 'Paid'])->assertOk()->assertJsonPath('status', 'active');

        $admin = User::where('email', 'ada@acme.test')->firstOrFail();
        $this->actAs($admin);
        $this->getJson('/api/dashboard')->assertOk();
        $this->assertNotNull($tenant->fresh()->approved_at);
    }

    public function test_scheduled_command_suspends_due_trials_only(): void
    {
        $this->register()->assertOk();
        $this->register(['company_name' => 'Fresh Ltd', 'email' => 'f@fresh.test'])->assertOk();
        Tenant::where('slug', 'acme-corp')->update(['trial_ends_at' => now()->subMinute()]);
        $this->artisan('trials:expire')->assertSuccessful();
        $this->assertSame('suspended', Tenant::where('slug', 'acme-corp')->value('status'));
        $this->assertSame('trial', Tenant::where('slug', 'fresh-ltd')->value('status'));
    }

    public function test_owner_can_manage_companies_and_is_never_locked(): void
    {
        $this->register()->assertOk();
        $tenant = Tenant::where('slug', 'acme-corp')->firstOrFail();
        $owner = $this->owner();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actAs($owner);

        $this->getJson('/api/owner/companies?status=trial')->assertOk()->assertJsonPath('total', 1);
        $this->postJson("/api/owner/companies/{$tenant->id}/suspend")->assertOk()->assertJsonPath('status', 'suspended');
        $this->postJson("/api/owner/companies/{$tenant->id}/reject")->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson("/api/owner/companies/{$tenant->id}/deactivate")->assertOk()->assertJsonPath('status', 'deactivated');
        $this->postJson("/api/owner/companies/{$tenant->id}/extend-trial", ['hours' => 24])->assertOk()->assertJsonPath('status', 'trial');
        $this->putJson("/api/owner/companies/{$tenant->id}/subscription", ['plan' => 'pro', 'modules' => ['people']])->assertOk()->assertJsonPath('plan', 'pro');
        $this->assertContains('people', $tenant->fresh()->settings['modules']);
        $this->assertNotContains('payroll', $tenant->fresh()->settings['modules']);
        $this->getJson("/api/owner/companies/{$tenant->id}")->assertOk()->assertJsonCount(1, 'users');

        // The platform workspace itself can never be managed as a customer company.
        $this->postJson("/api/owner/companies/{$owner->tenant_id}/suspend")->assertNotFound();
        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/audit')->assertOk();
    }

    public function test_regular_users_cannot_reach_owner_routes(): void
    {
        $this->register()->assertOk();
        $this->getJson('/api/owner/overview')->assertForbidden();
        $this->getJson('/api/owner/companies')->assertForbidden();
    }
}
