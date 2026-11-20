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
    }
}

