<?php

namespace Tests\Feature\Workflow;

use App\Domain\Workflow\TriggerDispatcher;
use App\Domain\Workflow\WorkflowService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Performance\PerformanceTestCase;

class WorkflowTest extends PerformanceTestCase
{
    public function test_schedule_respects_offset_and_delivery_failure_can_be_recovered(): void
    {
        $spec = ['steps' => [$this->step(['action' => 'task', 'assignment' => 'manager', 'assignees' => []])],
            'trigger' => ['type' => 'schedule', 'employee_uuid' => $this->emp1->uuid, 'at' => '2026-10-07T10:30:00+05:30', 'interval_hours' => 0]];
        $d = $this->postJson('/api/v1/workflows/definitions', ['name' => 'Timed task', 'spec' => $spec])->assertCreated()->json();
        $this->publish($d); $this->signIn($this->admin);
        app(TriggerDispatcher::class)->dispatch(); $this->assertSame(0, DB::table('workflow_deliveries')->count());
        $this->travel(31)->minutes(); $this->checker->update(['active' => false]);
        app(TriggerDispatcher::class)->dispatch();
        $row = DB::table('workflow_deliveries')->first(); $this->assertSame('retry', $row->status);
        $this->assertSame(0, DB::table('workflow_instances')->count());
        $this->checker->update(['active' => true]);
        $this->postJson('/api/v1/workflows/deliveries/'.$row->id.'/retry', ['reason' => 'Publisher access restored'])->assertOk();
        $this->signIn($this->admin); app(TriggerDispatcher::class)->dispatch();
        $this->assertSame('delivered', DB::table('workflow_deliveries')->value('status'));
        $this->assertSame(1, DB::table('workflow_instances')->count());
    }
    public function test_event_delivery_and_schedule_are_durable_and_idempotent(): void
    {
        $spec = ['steps' => [$this->step(['action' => 'task', 'assignment' => 'manager', 'assignees' => []])], 'trigger' => ['type' => 'event', 'event_type' => 'EmployeeCreated']];
        $d = $this->postJson('/api/v1/workflows/definitions', ['name' => 'New hire task', 'spec' => $spec])->assertCreated()->json();
        $this->publish($d);
        $this->signIn($this->admin);
        $employee = $this->hire('New Person', ['manager' => $this->mgr]);
        app(TriggerDispatcher::class)->dispatch();
        app(TriggerDispatcher::class)->dispatch();
        $this->assertSame(1, DB::table('workflow_deliveries')->count());
        $this->assertSame('delivered', DB::table('workflow_deliveries')->value('status'));
        $this->assertSame($employee->id, (int) DB::table('workflow_instances')->value('employee_id'));
        $spec['trigger'] = ['type' => 'schedule', 'employee_uuid' => $this->emp1->uuid, 'at' => '2026-10-07T00:00:00+00:00', 'interval_hours' => 0];
        $d = $this->postJson('/api/v1/workflows/definitions', ['name' => 'Scheduled check', 'spec' => $spec])->assertCreated()->json();
        $this->publish($d);
        $this->signIn($this->admin);
        app(TriggerDispatcher::class)->dispatch();
        app(TriggerDispatcher::class)->dispatch();
        $this->assertSame(2, DB::table('workflow_instances')->count());
        $this->assertSame(2, DB::table('workflow_deliveries')->count());
    }

    public function test_permission_changes_stop_decisions_and_retries_reach_dead_letter(): void
    {
        $d = $this->definition();
        $this->publish($d);
        $i = $this->start($d);
        $path = '/api/v1/workflows/instances/'.$i['uuid'];
        $this->signIn($this->checker);
        $permission = DB::table('permissions')->where('key', 'workflows.run')->value('id');
        $roles = $this->admin->roles()->pluck('roles.id');
        DB::table('permission_role')->whereIn('role_id', $roles)->where('permission_id', $permission)->delete();
        $this->postJson($path.'/decide', ['decision' => 'approved', 'reason' => 'Permissions changed'])->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('failure_code', 'initiator_permission_changed');
        $this->signIn($this->admin);
        foreach ([1, 2, 3] as $attempt) {
            $this->postJson($path.'/control', ['action' => 'retry', 'reason' => 'Recheck permissions'])->assertOk()->assertJsonPath('attempts', $attempt);
        }
        $this->getJson($path)->assertOk()->assertJsonPath('status', 'dead_letter');
        $this->postJson($path.'/control', ['action' => 'retry', 'reason' => 'Beyond retry limit'])->assertUnprocessable();
    }

    public function test_assignment_delegation_reassigns_task_and_preserves_independent_approval(): void
    {
        $manager = $this->userOf($this->mgr);
        $delegate = $this->checker;
        $d = $this->definition([$this->step(['assignees' => [$manager->id]])]);
        $this->publish($d);
        $i = $this->start($d);
        $path = '/api/v1/workflows/instances/'.$i['uuid'];

        $this->signIn($this->admin);
        $this->getJson($path.'/delegates')->assertOk()->assertJsonFragment(['id' => $delegate->id]);
        $this->postJson($path.'/delegate', ['from_user_id' => $manager->id, 'to_user_id' => $delegate->id, 'reason' => 'Out of office'])->assertOk();
        $instanceId = DB::table('workflow_instances')->where('uuid', $i['uuid'])->value('id');
        $this->assertSame(1, DB::table('workflow_assignments')->where('instance_id', $instanceId)->where('user_id', $manager->id)->where('status', 'delegated')->count());
        $this->assertSame(1, DB::table('workflow_assignments')->where('instance_id', $instanceId)->where('user_id', $delegate->id)->where('status', 'pending')->count());
        $this->assertSame(0, DB::table('work_items')->where('source_module', 'workflows')->where('assignee_id', $manager->id)->where('status', 'open')->count());
        $this->assertSame(1, DB::table('work_items')->where('source_module', 'workflows')->where('assignee_id', $delegate->id)->where('status', 'open')->count());

        $this->signIn($delegate);
        $this->postJson($path.'/decide', ['decision' => 'approved', 'reason' => 'Covered while manager was away'])->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_deadline_escalation_reassigns_once_then_fails_if_overdue_again(): void
    {
        $manager = $this->userOf($this->mgr);
        $delegate = $this->checker;
        $d = $this->definition([$this->step(['assignees' => [$manager->id], 'timeout_hours' => 1, 'escalation_hours' => 1, 'escalation_assignees' => [$delegate->id]])]);
        $this->publish($d);
        $i = $this->start($d);
        $path = '/api/v1/workflows/instances/'.$i['uuid'];

        $this->travel(2)->hours();
        $this->signIn($this->admin);
        app(WorkflowService::class)->tick();
        $this->getJson($path)->assertOk()->assertJsonPath('status', 'waiting');
        $this->assertSame(1, DB::table('workflow_assignments')->where('instance_id', DB::table('workflow_instances')->where('uuid', $i['uuid'])->value('id'))->where('status', 'escalated')->count());
        $this->assertSame(1, DB::table('workflow_assignments')->where('instance_id', DB::table('workflow_instances')->where('uuid', $i['uuid'])->value('id'))->where('status', 'pending')->where('user_id', $delegate->id)->count());
        $this->assertSame(1, DB::table('workflow_executions')->where('event', 'escalated')->count());
        DB::table('workflow_instances')->where('uuid', $i['uuid'])->update(['due_at' => now()->subMinute()]);
        $this->signIn($delegate);
        $this->postJson($path.'/decide', ['decision' => 'approved', 'reason' => 'Trying after escalation deadline'])->assertOk()
            ->assertJsonPath('status', 'failed')->assertJsonPath('failure_code', 'deadline_exceeded');
    }

    public function test_employment_status_trigger_filters_target_status(): void
    {
        $spec = ['steps' => [$this->step(['action' => 'task', 'assignment' => 'manager', 'assignees' => []])],
            'trigger' => ['type' => 'event', 'event_type' => 'EmploymentStatusChanged', 'status' => 'active']];
        $d = $this->postJson('/api/v1/workflows/definitions', ['name' => 'Activation task', 'spec' => $spec])->assertCreated()->json();
        $this->publish($d);
        $this->signIn($this->admin);
        app(\App\Domain\Events\DomainEvents::class)->record('EmploymentStatusChanged', ['employee' => $this->emp1->id, 'summary' => 'Suspended', 'payload' => ['to' => 'suspended']]);
        app(TriggerDispatcher::class)->dispatch();
        $this->assertSame(0, DB::table('workflow_deliveries')->count());
        app(\App\Domain\Events\DomainEvents::class)->record('EmploymentStatusChanged', ['employee' => $this->emp1->id, 'summary' => 'Activated', 'payload' => ['to' => 'active']]);
        app(TriggerDispatcher::class)->dispatch();
        $this->assertSame(1, DB::table('workflow_deliveries')->count());
        $this->assertSame(1, DB::table('workflow_instances')->count());
    }

    private function step(array $extra = []): array
    {
        return $extra + ['title' => 'Review employee request', 'action' => 'approval', 'assignment' => 'fixed', 'assignees' => [$this->checker->id], 'mode' => 'all', 'timeout_hours' => 24];
    }

    private function definition(?array $steps = null): array
    {
        $this->signIn($this->admin);

        return $this->postJson('/api/v1/workflows/definitions', ['name' => 'Employee request', 'spec' => ['steps' => $steps ?? [$this->step()]]])->assertCreated()->json();
    }

    private function publish(array $d): void
    {
        $this->signIn($this->checker);
        $this->postJson('/api/v1/workflows/definitions/'.$d['uuid'].'/publish', ['revision' => $d['revision']])->assertOk();
        $this->signIn($this->admin);
    }

    private function start(array $d, string $key = 'request-1'): array
    {
        return $this->postJson('/api/v1/workflows/instances', ['definition_uuid' => $d['uuid'], 'employee_uuid' => $this->emp1->uuid, 'idempotency_key' => $key])->assertCreated()->json();
    }

    public function test_version_publication_is_independent_and_simulation_has_no_side_effects(): void
    {
        $d = $this->definition();
        $this->postJson('/api/v1/workflows/definitions/'.$d['uuid'].'/publish', ['revision' => 1])->assertForbidden();
        $tasks = DB::table('work_items')->count();
        $notifications = DB::table('in_app_notifications')->count();
        $this->postJson('/api/v1/workflows/simulate', ['spec' => $d['draft'], 'employee_uuid' => $this->emp1->uuid])->assertOk()->assertJsonPath('side_effects', false)->assertJsonPath('steps.0.blocked', false);
        $this->assertSame($tasks, DB::table('work_items')->count());
        $this->assertSame($notifications, DB::table('in_app_notifications')->count());
        $this->assertSame(0, DB::table('workflow_instances')->count());
        $this->assertSame(0, DB::table('workflow_executions')->count());
        $this->publish($d);
        $this->putJson('/api/v1/workflows/definitions/'.$d['uuid'], ['name' => 'New draft', 'revision' => 1, 'spec' => ['steps' => [$this->step(['title' => 'Changed'])]]])->assertOk()->assertJsonPath('revision', 2);
        $this->start($d)['version'] === 1 ?: $this->fail('Instance did not pin the published version.');
        $this->putJson('/api/v1/workflows/definitions/'.$d['uuid'], ['name' => 'Stale', 'revision' => 1, 'spec' => $d['draft']])->assertConflict();
    }

    public function test_duplicate_starts_and_decisions_do_not_duplicate_tasks_or_effects(): void
    {
        $d = $this->definition();
        $this->publish($d);
        $i = $this->start($d);
        $again = $this->start($d);
        $this->assertSame($i['uuid'], $again['uuid']);
        $this->assertSame(1, DB::table('workflow_instances')->count());
        $task = DB::table('work_items')->where('source_module', 'workflows')->first();
        $this->assertNotNull($task);
        $this->patchJson('/api/work/'.$task->id, ['status' => 'completed'])->assertUnprocessable();
        $this->postJson('/api/v1/workflows/instances/'.$i['uuid'].'/decide', ['decision' => 'approved', 'reason' => 'Self review'])->assertForbidden();
        $this->signIn($this->checker);
        $this->postJson('/api/v1/workflows/instances/'.$i['uuid'].'/decide', ['decision' => 'approved', 'reason' => 'Checked'])->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson('/api/v1/workflows/instances/'.$i['uuid'].'/decide', ['decision' => 'approved', 'reason' => 'Replay'])->assertUnprocessable();
        $this->assertSame(0, DB::table('work_items')->where('source_module', 'workflows')->where('status', 'open')->count());
    }

    public function test_parallel_approvals_require_all_and_recheck_scope(): void
    {
        $manager = $this->userOf($this->mgr);
        $d = $this->definition([$this->step(['assignees' => [$this->checker->id, $manager->id]])]);
        $this->publish($d);
        $i = $this->start($d);
        $this->signIn($this->checker);
        $this->postJson('/api/v1/workflows/instances/'.$i['uuid'].'/decide', ['decision' => 'approved', 'reason' => 'First approval'])->assertOk()->assertJsonPath('status', 'waiting');
        $this->actAs($this->outsider);
        $this->getJson('/api/v1/workflows/instances/'.$i['uuid'])->assertNotFound();
        $this->actAs($this->mgr);
        $this->postJson('/api/v1/workflows/instances/'.$i['uuid'].'/decide', ['decision' => 'approved', 'reason' => 'Second approval'])->assertOk()->assertJsonPath('status', 'completed');
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->getJson('/api/v1/workflows/instances/'.$i['uuid'])->assertNotFound();
    }

    public function test_expiry_pause_resume_retry_and_permission_revocation(): void
    {
        $d = $this->definition();
        $this->publish($d);
        $i = $this->start($d);
        $path = '/api/v1/workflows/instances/'.$i['uuid'];
        $this->postJson($path.'/control', ['action' => 'pause', 'reason' => 'Waiting for information'])->assertOk()->assertJsonPath('status', 'paused');
        $this->signIn($this->checker);
        $this->postJson($path.'/decide', ['decision' => 'approved', 'reason' => 'While paused'])->assertUnprocessable();
        $this->signIn($this->admin);
        $this->postJson($path.'/control', ['action' => 'resume', 'reason' => 'Information received'])->assertOk();
        $this->travel(25)->hours();
        $this->signIn($this->admin);
        app(WorkflowService::class)->tick();
        $this->getJson($path)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('failure_code', 'deadline_exceeded');
        $this->postJson($path.'/control', ['action' => 'retry', 'reason' => 'Reviewer available'])->assertOk()->assertJsonPath('status', 'waiting');
        $this->checker->update(['active' => false]);
        $this->postJson($path.'/control', ['action' => 'cancel', 'reason' => 'No eligible reviewer'])->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson($path.'/control', ['action' => 'retry', 'reason' => 'Cannot replay cancelled case'])->assertUnprocessable();
    }

    public function test_invalid_actions_cycles_and_cross_tenant_assignees_are_rejected(): void
    {
        $this->postJson('/api/v1/workflows/definitions', ['name' => 'Unsafe', 'spec' => ['steps' => [$this->step(['action' => 'php'])]]])->assertUnprocessable();
        $this->postJson('/api/v1/workflows/definitions', ['name' => 'Cycle', 'spec' => ['steps' => [$this->step() + ['next' => 0]]]])->assertUnprocessable();
        $other = $this->bootTenant('beta');
        $this->context($this->checker->tenant_id);
        $this->signIn($this->checker);
        $this->postJson('/api/v1/workflows/definitions', ['name' => 'Cross tenant', 'spec' => ['steps' => [$this->step(['assignees' => [$other->id]])]]])->assertUnprocessable();
    }

    public function test_timers_conditions_and_notifications_advance_once(): void
    {
        $d = $this->definition([$this->step(['action' => 'notify', 'worker_type' => 'contractor']), $this->step(['action' => 'wait', 'timeout_hours' => 1]), $this->step(['action' => 'notify'])]);
        $this->publish($d);
        $i = $this->start($d);
        $this->assertSame('waiting', $i['status']);
        $this->assertSame(2, $i['step']);
        $this->travel(2)->hours();
        $this->signIn($this->admin);
        app(WorkflowService::class)->tick();
        app(WorkflowService::class)->tick();
        $this->getJson('/api/v1/workflows/instances/'.$i['uuid'])->assertOk()->assertJsonPath('status','completed');
        $this->assertSame(1,DB::table('workflow_executions')->where('event','notification_sent')->count());
    }
}
