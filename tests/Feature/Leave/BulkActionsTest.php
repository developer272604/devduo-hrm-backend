<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Models\LeaveTransfer;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BulkActionsTest extends PolicyTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
    }

    public function test_registered_action_previews_without_writes_and_runs_only_after_a_separate_request(): void
    {
        $employee = $this->hire('Asha Rao');
        $this->signIn($this->admin);

        $this->getJson('/api/v1/leave/bulk-actions')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'prepare_handling')
            ->assertJsonPath('data.0.max_employees', 500);

        $body = ['employees' => [$employee->uuid], 'params' => ['trigger' => 'transfer', 'effective_date' => '2026-10-11', 'balance_mode' => 'preserve']];
        $this->postJson('/api/v1/leave/bulk-actions/prepare_handling/preview', $body)
            ->assertOk()
            ->assertJsonPath('action', 'prepare_handling')
            ->assertJsonPath('count', 1)
            ->assertJsonPath('results.0.status', 'ok');
        $this->assertSame(0, LeaveTransfer::query()->count(), 'A preview must not create a transfer plan.');

        $this->postJson('/api/v1/leave/bulk-actions/prepare_handling/run', $body)
            ->assertOk()
            ->assertJsonPath('results.0.status', 'ok');
        $this->assertDatabaseHas('leave_transfers', ['employee_id' => $employee->id, 'trigger' => 'transfer', 'effective_date' => '2026-10-11', 'status' => 'preview']);
    }

    public function test_registry_and_runner_enforce_permissions_action_keys_and_parameter_validation(): void
    {
        $member = $this->userWithRole('Team member', 'bulk-member@alpha.test');
        $this->signIn($member);
        $this->getJson('/api/v1/leave/bulk-actions')->assertForbidden();
        $this->postJson('/api/v1/leave/bulk-actions/prepare_handling/run', [])->assertForbidden();

        $this->signIn($this->admin);
        $this->postJson('/api/v1/leave/bulk-actions/not_registered/preview', ['employees' => ['x'], 'params' => []])->assertNotFound();
        $employee = $this->hire('Maya Rao');
        $this->postJson('/api/v1/leave/bulk-actions/prepare_handling/preview', [
            'employees' => [$employee->uuid],
            'params' => ['trigger' => 'invalid', 'effective_date' => '2026-10-11'],
        ])->assertUnprocessable()->assertJsonValidationErrors('trigger');
    }

    public function test_runner_hides_employees_from_another_tenant(): void
    {
        $alphaAdmin = $this->admin;
        $this->bootTenant('beta');
        $foreignEmployee = $this->hire('Beta Employee');

        $this->signIn($alphaAdmin);
        $this->postJson('/api/v1/leave/bulk-actions/prepare_handling/preview', [
            'employees' => [$foreignEmployee->uuid],
            'params' => ['trigger' => 'transfer', 'effective_date' => '2026-10-11'],
        ])->assertNotFound();
    }
}
