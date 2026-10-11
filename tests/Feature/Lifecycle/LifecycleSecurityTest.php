<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Insights\ActionCenter;
use App\Domain\Lifecycle\Models\{LifecycleCase, LifecycleTask};
use App\Domain\Lifecycle\Operations\{LifecycleEmployee360Provider, LifecycleQualityRules};
use App\Domain\People\Models\Employee;
use App\Domain\Quality\DataQualityEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{LifecycleFixtures, PeopleFixtures};
use Tests\TestCase;

class LifecycleSecurityTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, LifecycleFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycleSetup();
    }

    public function test_permissions_gate_every_endpoint(): void
    {
        $member = $this->userWithRole('Team member', 'm@alpha.test');
        $manager = $this->userWithRole('Manager', 'mg@alpha.test');
        $this->assertArrayHasKey('lifecycle.moves.approve', WorkspaceProvisioner::PERMISSIONS);
        foreach ([$member, $manager] as $u) {
            $this->signIn($u);
            $this->getJson('/api/v1/lifecycle/cases')->assertForbidden();
            $this->getJson('/api/v1/lifecycle/dashboard')->assertForbidden();
            $this->postJson('/api/v1/lifecycle/templates', ['kind' => 'onboarding', 'name' => 'x', 'tasks' => [['title' => 't', 'owner_type' => 'hr']]])->assertForbidden();
            $this->postJson('/api/v1/lifecycle/moves', [])->assertForbidden();
            $this->getJson('/api/v1/lifecycle/alumni')->assertForbidden();
            $this->getJson('/api/v1/lifecycle/me/tasks')->assertOk(); // lifecycle.self is part of the default roles
        }
        $this->app['auth']->forgetGuards();
    }

    public function test_data_scope_hides_out_of_scope_employees_as_404(): void
    {
        $boss = $this->hire('Maya Boss', ['role' => 'Manager', 'email' => 'maya@alpha.test']);
        $mine = $this->hire('Mine Rep', ['manager' => $boss]);
        $other = $this->hire('Other Person');
        $this->publishedTemplate('onboarding', [['title' => 'T', 'owner_type' => 'hr']]);
        $this->context($this->admin->tenant_id);
        $theirs = $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $other->uuid, 'kind' => 'onboarding', 'anchor_date' => '2026-10-07'])->json('uuid');
        $own = $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $mine->uuid, 'kind' => 'onboarding', 'anchor_date' => '2026-10-07'])->json('uuid');

        $this->context($this->admin->tenant_id);
        // A scoped HR-style role (direct reports) sees only their team.
        $role = \App\Models\Role::create(['name' => 'Team lifecycle', 'description' => 'x', 'data_scope' => 'direct_reports', 'system' => false]);
        $role->permissions()->sync(\App\Models\Permission::whereIn('key', ['lifecycle.view', 'lifecycle.manage', 'lifecycle.moves.manage'])->pluck('id'));
        $boss->person->user->roles()->sync([$role->id]);
        $this->signIn($boss->person->user);
        $this->getJson('/api/v1/lifecycle/cases/'.$own)->assertOk();
        $this->getJson('/api/v1/lifecycle/cases/'.$theirs)->assertNotFound();
        $this->assertCount(1, $this->getJson('/api/v1/lifecycle/cases')->json('data'));
        $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $other->uuid, 'kind' => 'onboarding', 'anchor_date' => '2026-10-08'])->assertNotFound();
        $this->postJson('/api/v1/lifecycle/moves', ['employee_uuid' => $other->uuid, 'kind' => 'transfer', 'changes' => ['department_id' => $this->org['department'][1]], 'effective_date' => '2026-10-10'])->assertNotFound();
    }

    public function test_tenant_isolation(): void
    {
        $emp = $this->hire('Eva Eng');
        $this->publishedTemplate('onboarding', [['title' => 'T', 'owner_type' => 'hr']]);
        $this->context($this->admin->tenant_id);
        $case = $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $emp->uuid, 'kind' => 'onboarding', 'anchor_date' => '2026-10-07'])->assertCreated()->json();
        $move = $this->postJson('/api/v1/lifecycle/moves', ['employee_uuid' => $emp->uuid, 'kind' => 'location_change', 'changes' => ['location_id' => $this->org['location'][1]], 'effective_date' => '2026-10-09'])->json('uuid');
        $tpl = $this->getJson('/api/v1/lifecycle/templates')->json('0.uuid');

        $beta = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson('/api/v1/lifecycle/cases')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/lifecycle/cases/'.$case['uuid'])->assertNotFound();
        $this->getJson('/api/v1/lifecycle/templates/'.$tpl)->assertNotFound();
        $this->postJson('/api/v1/lifecycle/tasks/'.$case['tasks'][0]['uuid'].'/complete')->assertNotFound();
        $this->postJson('/api/v1/lifecycle/moves/'.$move.'/decision', ['decision' => 'approve'])->assertNotFound();
        $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $emp->uuid, 'kind' => 'onboarding'])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/lifecycle/dashboard')->json('blockers_by_owner'));
    }

    public function test_registries_data_quality_action_center_and_employee_360(): void
    {
        $pre = $this->hire('Pre Joiner', ['joined_on' => '2026-11-02', 'status' => 'preboarding']);
        $this->publishedTemplate('onboarding', [['title' => 'Owner-less task', 'owner_type' => 'employee']]); // employee has no login -> falls back to HR
        $this->context($this->admin->tenant_id);

        $engine = app(DataQualityEngine::class);
        $found = collect(LifecycleQualityRules::all())->first(fn ($r) => $r->key() === 'lifecycle_preboarder_without_checklist');
        $this->assertCount(1, iterator_to_array($found->scan($this->admin->tenant_id)));
        $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $pre->uuid, 'kind' => 'onboarding'])->assertCreated();
        $this->context($this->admin->tenant_id);
        $this->assertCount(0, iterator_to_array($found->scan($this->admin->tenant_id)));
        $this->assertNotNull($engine);

        $sections = app(LifecycleEmployee360Provider::class)->sections($this->admin, Employee::find($pre->id), []);
        $this->assertSame('Lifecycle', $sections[0]['title']);
        $this->assertSame([], app(LifecycleEmployee360Provider::class)->sections($this->userWithRole('Team member', 'x@alpha.test'), Employee::find($pre->id), []));

        $this->travelTo(now('UTC')->setDate(2026, 11, 20));
        $this->context($this->admin->tenant_id);
        $keys = collect(app(ActionCenter::class)->for($this->admin))->pluck('key')->all();
        $this->assertContains('lifecycle.overdue', $keys);
    }
}
