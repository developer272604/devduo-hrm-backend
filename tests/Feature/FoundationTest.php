<?php

namespace Tests\Feature;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Organization\OrganizationRecord;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\AuditLog;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = app(WorkspaceProvisioner::class)->create('Alpha', 'alpha', 'admin@alpha.test', 'StrongPassword123');
        $this->other = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
    }

    private function signIn(?User $u = null): void
    {
        $u ??= $this->admin;
        $u->refresh();
        $this->actingAs($u)->withSession(['version' => $u->session_version]);
    }

    private function member(): User
    {
        app(TenantContext::class)->id = $this->admin->tenant_id;
        $u = new User(['name' => 'Member', 'email' => 'member@alpha.test', 'password' => 'StrongPassword123']);
        $u->tenant_id = $this->admin->tenant_id;
        $u->save();
        $u->roles()->attach(Role::where('name', 'Team member')->firstOrFail());
        app(TenantContext::class)->id = null;

        return $u;
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        foreach (['/api/me', '/api/users', '/api/dashboard', '/api/work', '/api/audit', '/api/settings', '/api/notifications', '/api/organization/locations'] as $route) {
            $this->getJson($route)->assertUnauthorized();
        }
    }

    public function test_login_requires_matching_workspace_and_regenerates_session(): void
    {
        $this->postJson('/api/login', ['workspace' => 'beta', 'email' => 'admin@alpha.test', 'password' => 'StrongPassword123'])->assertStatus(422);
        $this->postJson('/api/login', ['workspace' => 'alpha', 'email' => 'admin@alpha.test', 'password' => 'StrongPassword123', 'remember' => true])->assertOk()->assertJsonPath('tenant.slug', 'alpha');
        $this->getJson('/api/me')->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        $payload = ['workspace' => 'alpha', 'email' => 'admin@alpha.test', 'password' => 'incorrect'];
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', $payload)->assertStatus(422);
        }
        $this->postJson('/api/login', $payload)->assertStatus(429);
    }

    public function test_tenant_records_fail_closed_without_context(): void
    {
        app(TenantContext::class)->id = $this->other->tenant_id;
        OrganizationRecord::forType('locations')->fill(['name' => 'Private office', 'code' => 'HQ'])->save();
        app(TenantContext::class)->id = null;
        $this->assertSame(0, OrganizationRecord::forType('locations')->newQuery()->count());
    }

    public function test_all_organization_types_are_isolated_for_reads_writes_and_deletes(): void
    {
        foreach (array_keys(OrganizationRecord::TYPES) as $type) {
            app(TenantContext::class)->id = $this->other->tenant_id;
            $record = OrganizationRecord::forType($type);
            $record->fill(['name' => 'Private '.$type, 'code' => 'PRIVATE'])->save();
            app(TenantContext::class)->id = null;
            $this->signIn();
            $this->getJson('/api/organization/'.$type)->assertOk()->assertJsonCount(0, 'data');
            $this->putJson('/api/organization/'.$type.'/'.$record->id, ['name' => 'Hacked', 'code' => 'HACK', 'active' => true])->assertNotFound();
            $this->deleteJson('/api/organization/'.$type.'/'.$record->id)->assertNotFound();
            $this->postJson('/api/organization/'.$type, ['name' => 'Our record', 'code' => 'PRIVATE', 'active' => true, 'tenant_id' => $this->other->tenant_id])->assertCreated()->assertJsonPath('tenant_id', $this->admin->tenant_id);
        }
    }

    public function test_users_roles_and_assignments_cannot_cross_tenants(): void
    {
        app(TenantContext::class)->id = $this->other->tenant_id;
        $role = Role::first();
        app(TenantContext::class)->id = null;
        $this->signIn();
        $this->getJson('/api/users')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'admin@alpha.test');
        $this->putJson('/api/users/'.$this->other->id, [])->assertNotFound();
        $this->putJson('/api/roles/'.$role->id, [])->assertNotFound();
        $this->postJson('/api/users', ['name' => 'Bad role', 'email' => 'new@alpha.test', 'password' => 'StrongPassword123', 'active' => true, 'role_ids' => [$role->id]])->assertUnprocessable()->assertJsonValidationErrors('role_ids.0');
    }

    public function test_rbac_denies_admin_apis_to_members(): void
    {
        $this->signIn($this->member());
        foreach (['/api/users', '/api/roles', '/api/permissions', '/api/settings', '/api/audit', '/api/organization/departments'] as $path) {
            $this->getJson($path)->assertForbidden();
        }
        $this->postJson('/api/organization/departments', ['name' => 'Test', 'code' => 'TEST', 'active' => true])->assertForbidden();
        $this->putJson('/api/setup', ['step' => 1, 'data' => ['name' => 'Hack', 'timezone' => 'UTC']])->assertForbidden();
    }

    public function test_tasks_enforce_assignee_scope_and_tenant_isolation(): void
    {
        $member = $this->member();
        app(TenantContext::class)->id = $this->admin->tenant_id;
        $task = app(TaskService::class)->create(['title' => 'Admin only', 'assignee_id' => $this->admin->id]);
        app(TenantContext::class)->id = $this->other->tenant_id;
        $foreign = app(TaskService::class)->create(['title' => 'Other tenant', 'assignee_id' => $this->other->id]);
        app(TenantContext::class)->id = null;
        $this->signIn($member);
        $this->getJson('/api/work')->assertJsonCount(0, 'data');
        $this->patchJson('/api/work/'.$task->id, ['status' => 'completed'])->assertNotFound();
        $this->postJson('/api/work', ['title' => 'Wrong assignee', 'assignee_id' => $this->admin->id, 'priority' => 'normal'])->assertForbidden();
        $this->signIn();
        $this->patchJson('/api/work/'.$foreign->id, ['status' => 'completed'])->assertNotFound();
        $this->postJson('/api/work', ['title' => 'Cross tenant', 'assignee_id' => $this->other->id, 'priority' => 'normal'])->assertUnprocessable();
    }

    public function test_task_lifecycle_filters_notifications_and_audits(): void
    {
        $this->signIn();
        $id = $this->postJson('/api/work', ['title' => 'Review setup', 'assignee_id' => $this->admin->id, 'priority' => 'high', 'due_date' => today()->subDay()->toDateString()])->assertCreated()->json('id');
        $this->getJson('/api/work?tab=overdue')->assertJsonCount(1, 'data');
        $this->getJson('/api/work?tab=due-soon')->assertJsonCount(0, 'data');
        $this->getJson('/api/notifications?filter=unread')->assertJsonCount(1, 'data');
        $this->patchJson('/api/work/'.$id, ['status' => 'completed'])->assertOk();
        $this->getJson('/api/work?tab=completed')->assertJsonCount(1, 'data');
        $this->getJson('/api/audit?module=work&action=completed')->assertJsonCount(1, 'data');
        $this->postJson('/api/notifications/read-all')->assertNoContent();
        $this->getJson('/api/notifications?filter=unread')->assertJsonCount(0, 'data');
    }

    public function test_notifications_are_private_even_for_admin(): void
    {
        $member = $this->member();
        app(TenantContext::class)->id = $member->tenant_id;
        $n = InAppNotification::create(['user_id' => $member->id, 'title' => 'Private', 'body' => 'Private note', 'category' => 'security']);
        app(TenantContext::class)->id = null;
        $this->signIn();
        $this->getJson('/api/notifications')->assertJsonCount(0, 'data');
        $this->postJson('/api/notifications/'.$n->id.'/read')->assertNotFound();
    }

    public function test_last_admin_and_system_role_are_protected(): void
    {
        app(TenantContext::class)->id = $this->admin->tenant_id;
        $adminRole = Role::where('system', true)->first();
        $memberRole = Role::where('system', false)->first();
        app(TenantContext::class)->id = null;
        $this->signIn();
        $this->deleteJson('/api/roles/'.$adminRole->id)->assertUnprocessable();
        $this->putJson('/api/roles/'.$adminRole->id, [])->assertUnprocessable();
        $this->putJson('/api/users/'.$this->admin->id, ['name' => 'Admin', 'email' => $this->admin->email, 'active' => true, 'role_ids' => [$memberRole->id]])->assertUnprocessable();
    }

    public function test_setup_requires_all_steps_and_persists_atomically(): void
    {
        $this->signIn();
        $this->putJson('/api/setup', ['step' => 8, 'data' => [], 'complete' => true])->assertUnprocessable();
        $steps = [1 => ['name' => 'Alpha Works', 'timezone' => 'Asia/Kolkata'], 2 => ['legal_entity' => 'Alpha Ltd', 'registration_number' => 'REG-1'], 3 => ['location' => 'Bengaluru', 'address' => 'Studio 1'], 4 => ['departments' => ['Engineering', 'People']], 5 => ['work_week' => [1, 2, 3, 4, 5]], 6 => ['employee_types' => ['Full time', 'Contract']], 7 => ['hr_email' => 'hr@alpha.test', 'notifications' => true]];
        foreach ($steps as $step => $data) {
            $this->putJson('/api/setup', compact('step', 'data'))->assertOk();
        }
        $this->getJson('/api/settings')->assertJsonPath('setup_draft.4.departments.0', 'Engineering');
        $this->putJson('/api/setup', ['step' => 8, 'data' => [], 'complete' => true])->assertOk();
        $this->getJson('/api/organization/departments')->assertJsonCount(2, 'data');
        $this->getJson('/api/organization/legal-entities')->assertJsonPath('data.0.metadata.registration_number', 'REG-1');
        $this->assertNotNull($this->admin->tenant->fresh()->setup_completed_at);
        $this->assertNull($this->other->tenant->fresh()->setup_completed_at);
        $this->putJson('/api/setup', ['step' => 8, 'data' => [], 'complete' => true])->assertUnprocessable();
    }

    public function test_invalid_forms_return_field_errors(): void
    {
        $this->signIn();
        $this->postJson('/api/organization/departments', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'code', 'active']);
        $this->putJson('/api/setup', ['step' => 5, 'data' => ['work_week' => [9]]])->assertUnprocessable();
        $this->getJson('/api/organization/not-a-table')->assertNotFound();
    }

    public function test_disabled_modules_are_enforced_on_server(): void
    {
        $this->admin->tenant->update(['settings' => ['modules' => []]]);
        $this->signIn();
        $this->getJson('/api/work')->assertForbidden();
        $this->getJson('/api/notifications')->assertForbidden();
        $this->getJson('/api/dashboard')->assertJsonPath('open_tasks', null);
    }

    public function test_session_revocation_and_inactive_account(): void
    {
        $this->signIn();
        $this->withSession(['version' => 0])->getJson('/api/me')->assertUnauthorized();
        $this->admin->update(['active' => false]);
        $this->signIn();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_password_change_checks_current_and_revokes_other_sessions(): void
    {
        $this->signIn();
        $this->postJson('/api/change-password', ['current_password' => 'Wrong', 'password' => 'NewStrongPassword123', 'password_confirmation' => 'NewStrongPassword123'])->assertUnprocessable();
        $this->postJson('/api/change-password', ['current_password' => 'StrongPassword123', 'password' => 'NewStrongPassword123', 'password_confirmation' => 'NewStrongPassword123'])->assertOk();
        $this->assertTrue(Hash::check('NewStrongPassword123', $this->admin->fresh()->password));
        $this->assertSame(2, $this->admin->fresh()->session_version);
        $this->withSession(['version' => 1])->getJson('/api/me')->assertUnauthorized();
    }

    public function test_password_recovery_uses_single_use_tokens_and_neutral_response(): void
    {
        Notification::fake();
        $known = $this->postJson('/api/forgot-password', ['email' => $this->admin->email])->assertOk();
        $unknown = $this->postJson('/api/forgot-password', ['email' => 'unknown@alpha.test'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTo($this->admin, ResetPassword::class);
        $token = Password::createToken($this->admin);
        $payload = ['email' => $this->admin->email, 'token' => $token, 'password' => 'ResetStrongPassword123', 'password_confirmation' => 'ResetStrongPassword123'];
        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
        $this->assertSame(2, $this->admin->fresh()->session_version);
    }

    public function test_audit_log_never_leaks_other_tenants_or_passwords(): void
    {
        app(TenantContext::class)->id = $this->other->tenant_id;
        AuditLog::create(['actor' => 'Other', 'action' => 'created', 'module' => 'settings', 'resource' => 'private']);
        app(TenantContext::class)->id = null;
        $this->signIn();
        $this->getJson('/api/audit')->assertJsonCount(0,'data');
        $this->getJson('/api/me')->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.remember_token');
    }
}
