<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Lifecycle\Models\{LifecycleCase, LifecycleTask};
use App\Domain\People\Models\Employee;
use App\Models\{User, WorkItem};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\{LifecycleFixtures, PeopleFixtures};
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, LifecycleFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycleSetup();
    }

    private function tasks(): array
    {
        return [
            ['title' => 'Prepare laptop', 'group_name' => 'IT', 'owner_type' => 'it', 'owner_user_id' => $this->checker->id, 'due_offset_days' => -2, 'mandatory' => true],
            ['title' => 'Welcome call', 'group_name' => 'Manager', 'owner_type' => 'manager', 'due_offset_days' => 0, 'mandatory' => true],
            ['title' => 'Upload identity proof', 'group_name' => 'Employee', 'owner_type' => 'employee', 'due_offset_days' => 3, 'mandatory' => true, 'requires_document' => true],
            ['title' => 'Buddy intro', 'group_name' => 'Manager', 'owner_type' => 'manager', 'due_offset_days' => 5, 'mandatory' => false],
        ];
    }

    public function test_template_to_instance_with_owners_due_dates_and_my_work(): void
    {
        $manager = $this->hire('Maya Boss', ['role' => 'Manager', 'email' => 'maya@alpha.test']);
        $hire = $this->hire('Ravi New', ['manager' => $manager, 'joined_on' => '2026-11-02', 'status' => 'preboarding', 'role' => 'Team member', 'email' => 'ravi@alpha.test']);
        $this->publishedTemplate('onboarding', $this->tasks());
        $this->context($this->admin->tenant_id);

        $case = $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $hire->uuid, 'kind' => 'onboarding'])->assertCreated()->json();
        $this->assertSame('2026-11-02', $case['anchor_date']);
        $this->assertCount(4, $case['tasks']);
        $byTitle = collect($case['tasks'])->keyBy('title');
        $this->assertSame('2026-10-31', $byTitle['Prepare laptop']['due_date']);
        $this->assertSame('2026-11-05', $byTitle['Upload identity proof']['due_date']);
        $this->context($this->admin->tenant_id);
        $mgrUser = User::where('email', 'maya@alpha.test')->first();
        $this->assertSame($mgrUser->id, LifecycleTask::where('title', 'Welcome call')->value('owner_user_id'));
        $this->assertSame(User::where('email', 'ravi@alpha.test')->value('id'), LifecycleTask::where('title', 'Upload identity proof')->value('owner_user_id'));
        $this->assertSame(4, WorkItem::where('source_module', 'lifecycle')->count()); // My Work
        $this->assertFalse($case['readiness']['day_one_ready']);

        // Idempotent regeneration returns the same open case; no duplicate tasks or My Work items.
        $again = $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $hire->uuid, 'kind' => 'onboarding'])->assertCreated()->json();
        $this->assertSame($case['uuid'], $again['uuid']);
        $this->context($this->admin->tenant_id);
        $this->assertSame(4, LifecycleTask::count());
        $this->assertSame(4, WorkItem::where('source_module', 'lifecycle')->count());
    }

    public function test_task_completion_document_gate_day_one_readiness_and_case_completion(): void
    {
        $hire = $this->hire('Ravi New', ['joined_on' => '2026-11-02', 'status' => 'preboarding', 'role' => 'Team member', 'email' => 'ravi@alpha.test']);
        $this->publishedTemplate('onboarding', $this->tasks());
        $this->context($this->admin->tenant_id);
        $case = $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $hire->uuid, 'kind' => 'onboarding'])->json();
        $tasks = collect($case['tasks'])->keyBy('title');

        // The employee completes their own document task, but must reference the document.
        $this->signIn(User::where('email', 'ravi@alpha.test')->first());
        $this->getJson('/api/v1/lifecycle/me/tasks')->assertOk()->assertJsonCount(1);
        $id = $tasks['Upload identity proof']['uuid'];
        $this->postJson('/api/v1/lifecycle/tasks/'.$id.'/complete')->assertStatus(422)->assertJsonPath('code', 'document_required');
        $this->postJson('/api/v1/lifecycle/tasks/'.$id.'/complete', ['document_ref' => 'doc-123'])->assertOk()->assertJsonPath('status', 'done');
        $this->postJson('/api/v1/lifecycle/tasks/'.$tasks['Prepare laptop']['uuid'].'/complete')->assertNotFound(); // not theirs

        $this->signIn($this->admin);
        $this->postJson('/api/v1/lifecycle/tasks/'.$tasks['Buddy intro']['uuid'].'/complete', ['outcome' => 'skipped'])->assertOk();
        $this->postJson('/api/v1/lifecycle/tasks/'.$tasks['Welcome call']['uuid'].'/complete', ['outcome' => 'skipped'])->assertStatus(422); // mandatory
        $this->getJson('/api/v1/lifecycle/cases/'.$case['uuid'])->assertOk()->assertJsonPath('readiness.day_one_ready', false)->assertJsonPath('readiness.mandatory_pending', 2);
        $this->postJson('/api/v1/lifecycle/tasks/'.$tasks['Prepare laptop']['uuid'].'/complete')->assertOk();
        $this->postJson('/api/v1/lifecycle/tasks/'.$tasks['Welcome call']['uuid'].'/complete')->assertOk();
        $this->getJson('/api/v1/lifecycle/cases/'.$case['uuid'])->assertJsonPath('status', 'completed')->assertJsonPath('readiness.day_one_ready', true);
    }

    public function test_versions_are_immutable_and_conditions_pick_the_most_specific_template(): void
    {
        $uk = $this->hire('Una Kay', ['joined_on' => '2026-11-02', 'status' => 'preboarding', 'location' => 1]);
        $in = $this->hire('Ind Ra', ['joined_on' => '2026-11-02', 'status' => 'preboarding']);
        $generic = $this->publishedTemplate('onboarding', [['title' => 'Generic task', 'owner_type' => 'hr']], [], 'Generic');
        $this->publishedTemplate('onboarding', [['title' => 'UK right-to-work check', 'owner_type' => 'hr']], ['country' => 'GB'], 'UK');
        $this->context($this->admin->tenant_id);

        $ukCase = $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $uk->uuid, 'kind' => 'onboarding'])->json();
        $inCase = $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $in->uuid, 'kind' => 'onboarding'])->json();
        $this->assertSame('UK right-to-work check', $ukCase['tasks'][0]['title']);
        $this->assertSame('Generic task', $inCase['tasks'][0]['title']);

        // A new version of the generic template retires v1 for new cases; the existing case keeps its tasks.
        $v2 = $this->postJson('/api/v1/lifecycle/templates/'.$generic.'/versions', ['tasks' => [['title' => 'Generic task v2', 'owner_type' => 'hr']]])->assertCreated()->json('versions.1.uuid');
        $this->postJson('/api/v1/lifecycle/template-versions/'.$v2.'/publish')->assertOk();
        $this->postJson('/api/v1/lifecycle/template-versions/'.$v2.'/publish')->assertConflict();
        $this->context($this->admin->tenant_id);
        $third = $this->hire('Third Hire', ['joined_on' => '2026-11-03', 'status' => 'preboarding']);
        $this->context($this->admin->tenant_id);
        $this->assertSame('Generic task v2', $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $third->uuid, 'kind' => 'onboarding'])->json('tasks.0.title'));
        $this->assertSame('Generic task', $this->getJson('/api/v1/lifecycle/cases/'.$inCase['uuid'])->json('tasks.0.title'));
    }

    public function test_delayed_joining_shifts_pending_due_dates(): void
    {
        $hire = $this->hire('Ravi New', ['joined_on' => '2026-11-02', 'status' => 'preboarding']);
        $this->publishedTemplate('onboarding', $this->tasks());
        $this->context($this->admin->tenant_id);
        $case = $this->postJson('/api/v1/lifecycle/cases', ['employee_uuid' => $hire->uuid, 'kind' => 'onboarding'])->json();
        $res = $this->postJson('/api/v1/lifecycle/cases/'.$case['uuid'].'/reschedule', ['anchor_date' => '2026-11-09'])->assertOk()->json();
        $this->assertSame('2026-11-09', $res['anchor_date']);
        $this->assertSame('2026-11-07', collect($res['tasks'])->firstWhere('title', 'Prepare laptop')['due_date']);
    }
}
