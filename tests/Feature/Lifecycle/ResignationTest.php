<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Lifecycle\Models\LifecycleCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{LifecycleFixtures, PeopleFixtures};
use Tests\TestCase;

class ResignationTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, LifecycleFixtures;

    public function test_ownership_self_approval_and_pending_withdrawal(): void
    {
        $this->lifecycleSetup();
        $employee = $this->hire('HR Employee', ['role' => 'Workspace administrator', 'email' => 'hr-resign@alpha.test']);
        $other = $this->hire('Other Employee');
        $this->context($this->admin->tenant_id);
        $user = User::where('email', 'hr-resign@alpha.test')->firstOrFail();
        $this->signIn($user);
        $data = ['employee_uuid' => $employee->uuid, 'requested_last_day' => '2027-01-15', 'reason' => 'Personal plans', 'confirmed' => true];
        $this->postJson('/api/v1/lifecycle/me/resignations', array_merge($data, ['employee_uuid' => $other->uuid]))->assertNotFound();
        $uuid = $this->postJson('/api/v1/lifecycle/me/resignations', $data)->assertCreated()->json('uuid');
        $this->postJson('/api/v1/lifecycle/resignations/'.$uuid.'/decision', ['decision' => 'approve', 'expected_status' => 'pending', 'note' => 'Self'])->assertForbidden();
        $this->signIn($this->admin);
        $this->getJson('/api/v1/lifecycle/me/resignations')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/lifecycle/me/resignations/'.$uuid.'/withdraw', ['reason' => 'Other user', 'confirmed' => true])->assertNotFound();
        $this->signIn($user);
        $this->postJson('/api/v1/lifecycle/me/resignations/'.$uuid.'/withdraw', ['reason' => 'Changed mind', 'confirmed' => true])->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, \App\Models\WorkItem::where('source_entity', 'resignation:'.$uuid)->where('status', 'open')->count());
    }

    public function test_rejection_and_direct_people_withdrawal_stay_consistent(): void
    {
        $this->lifecycleSetup();
        $employee = $this->hire('Direct Withdrawal', ['role' => 'Team member', 'email' => 'direct@alpha.test']);
        $this->context($this->admin->tenant_id);
        $user = User::where('email', 'direct@alpha.test')->firstOrFail();
        $data = ['employee_uuid' => $employee->uuid, 'requested_last_day' => '2027-01-15', 'reason' => 'Personal plans', 'confirmed' => true];
        $this->signIn($user);
        $uuid = $this->postJson('/api/v1/lifecycle/me/resignations', $data)->assertCreated()->json('uuid');
        $this->signIn($this->admin);
        $this->postJson('/api/v1/lifecycle/resignations/'.$uuid.'/decision', ['decision' => 'reject', 'expected_status' => 'pending', 'note' => 'Discuss a revised date'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->signIn($user);
        $uuid = $this->postJson('/api/v1/lifecycle/me/resignations', $data)->assertCreated()->json('uuid');
        $this->signIn($this->admin);
        $this->postJson('/api/v1/lifecycle/resignations/'.$uuid.'/decision', ['decision' => 'approve', 'expected_status' => 'pending', 'note' => 'Approved'])->assertOk();
        $this->signIn($user);
        $this->postJson('/api/v1/lifecycle/me/resignations/'.$uuid.'/withdraw', ['reason' => 'Changed mind', 'confirmed' => true])->assertOk();
        $this->signIn($this->admin);
        $this->context($this->admin->tenant_id);
        app(\App\Domain\People\Lifecycle\SeparationService::class)->withdraw($this->admin, $employee, 'Handled in People');
        app(\App\Domain\Lifecycle\LifecycleJobs::class)->housekeeping();
        $this->assertSame(0, \App\Models\WorkItem::where('source_entity', 'resignation:'.$uuid)->where('status', 'open')->count());
        $this->signIn($user);
        $this->getJson('/api/v1/lifecycle/me/resignations')->assertOk()->assertJsonPath('data.0.status', 'withdrawn')->assertJsonPath('data.0.can_withdraw', false);
        $this->postJson('/api/v1/lifecycle/me/resignations', $data)->assertCreated();
    }

    public function test_employee_request_hr_approval_and_reviewed_withdrawal(): void
    {
        $this->lifecycleSetup();
        $employee = $this->hire('Resigning Employee', ['role' => 'Team member', 'email' => 'resign@alpha.test']);
        $this->context($this->admin->tenant_id);
        $user = User::where('email', 'resign@alpha.test')->firstOrFail();
        $this->signIn($user);
        $data = ['employee_uuid' => $employee->uuid, 'requested_last_day' => '2027-01-15', 'reason' => 'Personal plans', 'confirmed' => true];
        $this->postJson('/api/v1/lifecycle/me/resignations', array_merge($data, ['confirmed' => false]))->assertUnprocessable();
        $uuid = $this->postJson('/api/v1/lifecycle/me/resignations', $data)->assertCreated()->assertJsonPath('status', 'pending')->json('uuid');
        $this->postJson('/api/v1/lifecycle/me/resignations', $data)->assertConflict();
        $this->context($this->admin->tenant_id);
        $this->assertNotSame('notice_period', $employee->fresh()->status);
        $this->assertNotSame('Personal plans', \DB::table('lifecycle_resignations')->where('uuid', $uuid)->value('reason'));
        $decision = '/api/v1/lifecycle/resignations/'.$uuid.'/decision';
        $this->postJson($decision, ['decision' => 'approve', 'expected_status' => 'pending', 'note' => 'Approved'])->assertForbidden();
        $this->signIn($this->admin);
        $this->postJson($decision, ['decision' => 'approve', 'expected_status' => 'pending', 'note' => 'Approved'])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson($decision, ['decision' => 'approve', 'expected_status' => 'pending', 'note' => 'Retry'])->assertConflict();
        $this->context($this->admin->tenant_id);
        $this->assertSame('notice_period', $employee->fresh()->status);
        $this->assertSame(1, LifecycleCase::where('employee_id', $employee->id)->where('kind', 'offboarding')->count());
        $this->signIn($user);
        $this->postJson('/api/v1/lifecycle/me/resignations/'.$uuid.'/withdraw', ['reason' => 'Plans changed', 'confirmed' => true])->assertOk()->assertJsonPath('status', 'withdrawal_pending');
        $this->signIn($this->admin);
        $this->postJson($decision, ['decision' => 'approve', 'expected_status' => 'withdrawal_pending', 'note' => 'Withdrawal accepted'])->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->context($this->admin->tenant_id);
        $this->assertNotSame('notice_period', $employee->fresh()->status);
        $this->assertSame('cancelled', LifecycleCase::where('employee_id', $employee->id)->where('kind', 'offboarding')->value('status'));
    }
}
