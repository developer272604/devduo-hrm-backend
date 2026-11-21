<?php

namespace Tests\Feature;

use App\Domain\Access\WorkspaceProvisioner;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/v1/projects';
    private const WEEK = '2026-11-16';

    private function signIn(User $user): void
    {
        $user->refresh();
        $this->actingAs($user)->withSession(['version' => $user->session_version]);
    }

    public function test_weekly_submission_review_reporting_and_tenant_isolation(): void
    {
        $provisioner = app(WorkspaceProvisioner::class);
        $admin = $provisioner->create('Projects', 'projects', 'admin@projects.test', 'StrongPassword123');
        $other = $provisioner->create('Outside', 'outside', 'admin@outside.test', 'StrongPassword123');
        $member = new User(['name' => 'Project member', 'email' => 'member@projects.test', 'password' => 'StrongPassword123']);
        $member->tenant_id = $admin->tenant_id;
        $member->save();
        $member->roles()->attach(Role::withoutGlobalScopes()->where('tenant_id', $admin->tenant_id)->where('name', 'Team member')->sole());

        $this->getJson(self::BASE)->assertUnauthorized();
        $this->signIn($admin);
        $project = $this->postJson(self::BASE, ['name' => 'Implementation'])->assertCreated()->json('uuid');
        $members = self::BASE.'/'.$project.'/members';
        $this->putJson($members, ['user_id' => $other->id, 'assigned' => true])->assertNotFound();
        $this->getJson(self::BASE.'/people?project='.$project)->assertOk()->assertJsonCount(2, 'data');

        $this->signIn($member);
        $this->postJson(self::BASE, ['name' => 'Restricted'])->assertForbidden();
        $this->getJson(self::BASE)->assertJsonCount(0, 'data');
        $input = [
            'week_start' => self::WEEK,
            'version' => 0,
            'submit' => true,
            'entries' => [
                ['project' => $project, 'date' => self::WEEK, 'minutes' => 90, 'billable' => true],
                ['project' => $project, 'date' => '2026-11-17', 'minutes' => 30, 'billable' => false],
            ],
        ];
        $this->putJson(self::BASE.'/timesheet', $input)->assertUnprocessable();

        $this->signIn($admin);
        $taskPath = self::BASE.'/'.$project.'/tasks';
        $taskInput = ['title' => 'Prepare project handover', 'assignee_id' => $member->id];
        $this->postJson($taskPath, $taskInput)->assertUnprocessable();
        $this->putJson($members, ['user_id' => $member->id, 'assigned' => true])->assertNoContent();
        $this->postJson($taskPath, $taskInput)->assertCreated()->assertJsonPath('source_entity', $project);
        $this->putJson($members, ['user_id' => $admin->id, 'assigned' => true])->assertNoContent();
        $own = $this->putJson(self::BASE.'/timesheet', $input)->assertOk()->json();
        $this->postJson(self::BASE.'/reviews/'.$own['uuid'], ['decision' => 'approved', 'version' => 1])->assertForbidden();

        $this->signIn($member);
        $this->getJson(self::BASE)->assertJsonPath('data.0.uuid', $project);
        $this->getJson('/api/work')->assertJsonPath('data.0.title', 'Prepare project handover');
        $this->putJson(self::BASE.'/timesheet', array_replace($input, ['submit' => false]))->assertOk()->assertJsonPath('status', 'draft');
        $this->putJson(self::BASE.'/timesheet', $input)->assertConflict();
        $sheet = $this->putJson(self::BASE.'/timesheet', array_replace($input, ['version' => 1]))
            ->assertOk()->assertJsonPath('status', 'submitted')->json('uuid');
        $this->putJson(self::BASE.'/timesheet', array_replace($input, ['version' => 2]))->assertConflict();

        $this->signIn($other);
        $this->getJson(self::BASE.'/reviews')->assertJsonCount(0, 'data');
        $this->postJson(self::BASE.'/reviews/'.$sheet, ['decision' => 'approved', 'version' => 2])->assertNotFound();
        $this->putJson($members, ['user_id' => $other->id, 'assigned' => true])->assertNotFound();

        $this->signIn($admin);
        $this->getJson(self::BASE.'/reviews')->assertJsonPath('data.0.uuid', $sheet)->assertJsonCount(1, 'data');
        $decision = self::BASE.'/reviews/'.$sheet;
        $this->postJson($decision, ['decision' => 'rejected', 'version' => 2])->assertUnprocessable();
        $this->postJson($decision, ['decision' => 'rejected', 'version' => 2, 'note' => 'Please verify the dates.'])->assertOk();
        $this->getJson(self::BASE.'/report?week_start='.self::WEEK)->assertExactJson([]);

        $this->signIn($member);
        $this->getJson(self::BASE.'/timesheet?week_start='.self::WEEK)->assertJsonPath('review_note', 'Please verify the dates.');
        $this->putJson(self::BASE.'/timesheet', array_replace($input, ['version' => 3]))->assertOk()->assertJsonPath('version', 4);

        $this->signIn($admin);
        $this->postJson($decision, ['decision' => 'approved', 'version' => 4])->assertOk();
        $this->postJson($decision, ['decision' => 'approved', 'version' => 4])->assertConflict();
        $this->getJson(self::BASE.'/report?week_start='.self::WEEK)
            ->assertExactJson([['project' => $project, 'name' => 'Implementation', 'billable' => 90, 'non_billable' => 30]]);

        $planning = self::BASE.'/'.$project.'/planning';
        $plan = ['week_start' => self::WEEK, 'client_name' => 'Client A', 'planned_minutes' => 240];
        $this->putJson($planning, array_replace($plan, ['planned_minutes' => 0]))->assertUnprocessable();
        $this->putJson($planning, $plan)->assertNoContent();
        $this->getJson($planning.'?week_start='.self::WEEK)->assertExactJson(['client_name' => 'Client A', 'planned_minutes' => 240]);
        $this->getJson(self::BASE.'/report?week_start='.self::WEEK)
            ->assertJsonPath('0.planned_minutes', 240)->assertJsonPath('0.client_name', 'Client A');
        $this->getJson(self::BASE.'/report?week_start=2026-11-23')->assertExactJson([]);

        $this->signIn($member);
        $this->getJson(self::BASE)->assertJsonMissingPath('data.0.client_name');
        $this->putJson($planning, $plan)->assertForbidden();

        $this->signIn($other);
        $this->putJson($planning, $plan)->assertNotFound();
        $this->getJson($planning.'?week_start='.self::WEEK)->assertNotFound();

        $this->signIn($admin);
        $this->getJson(self::BASE.'?assigned=1')->assertJsonMissingPath('data.0.client_name');
        $this->putJson($planning, array_replace($plan, ['planned_minutes' => null]))->assertNoContent();
        $this->getJson(self::BASE.'/report?week_start='.self::WEEK)->assertJsonMissingPath('0.planned_minutes');

        $rates = ['currency' => 'INR', 'billing_rate' => '20.01', 'labor_rate' => '12.34', 'direct_cost' => '10.00'];
        $this->putJson($planning, $plan + ['costing' => array_replace($rates, ['billing_rate' => '20.001'])])->assertUnprocessable();
        $this->putJson($planning, $plan + ['costing' => $rates])->assertNoContent();
        $this->getJson($planning.'?week_start='.self::WEEK)->assertJsonPath('costing', $rates);
        $this->getJson(self::BASE.'/report?week_start='.self::WEEK)
            ->assertJsonPath('0.costing.currency', 'INR')
            ->assertJsonPath('0.costing.revenue', '30.02')
            ->assertJsonPath('0.costing.labor_cost', '24.68')
            ->assertJsonPath('0.costing.total_cost', '34.68')
            ->assertJsonPath('0.costing.contribution', '-4.66');
        $this->putJson($planning, $plan)->assertNoContent();
        $this->getJson($planning.'?week_start='.self::WEEK)->assertJsonPath('costing', $rates);
        $this->putJson($planning, $plan + ['costing' => null])->assertNoContent();
        $this->getJson($planning.'?week_start='.self::WEEK)->assertJsonMissingPath('costing');
    }
}

