<?php

namespace Tests\Feature\Lifecycle;

use App\Domain\Lifecycle\LifecycleJobs;
use App\Domain\People\Models\{Assignment, ReportingRelationship};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{LifecycleFixtures, PeopleFixtures};
use Tests\TestCase;

class InternalMoveTest extends TestCase
{
    use RefreshDatabase, PeopleFixtures, LifecycleFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lifecycleSetup();
    }

    public function test_promotion_is_approved_by_another_person_and_applied_effective_dated(): void
    {
        $emp = $this->hire('Eva Eng', ['role' => 'Team member', 'email' => 'eva@alpha.test']);
        $this->context($this->admin->tenant_id);
        $move = $this->postJson('/api/v1/lifecycle/moves', ['employee_uuid' => $emp->uuid, 'kind' => 'promotion', 'changes' => ['designation_id' => $this->org['designation'][1], 'bogus' => 1],
            'effective_date' => '2026-10-20', 'reason' => 'Annual cycle'])->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('employee_name', 'Eva Eng')->assertJsonPath('can_decide', false)->json();
        $this->assertArrayNotHasKey('bogus', $move['changes']);

        $this->postJson('/api/v1/lifecycle/moves/'.$move['uuid'].'/decision', ['decision' => 'approve'])->assertForbidden(); // maker cannot check
        $this->signIn($this->checker)->getJson('/api/v1/lifecycle/moves')->assertOk()->assertJsonPath('data.0.employee.name', 'Eva Eng')->assertJsonPath('data.0.employee_name', 'Eva Eng')->assertJsonPath('data.0.can_decide', true);
        $this->postJson('/api/v1/lifecycle/moves/'.$move['uuid'].'/decision', ['decision' => 'approve'])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('can_decide', false); // future dated: not yet applied
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Assignment::where('employee_id', $emp->id)->count());

        // Daily job applies it once the date arrives; running twice changes nothing more.
        $this->travelTo(now('UTC')->setDate(2026, 10, 20)->setTime(5, 0));
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, app(LifecycleJobs::class)->housekeeping()['moves_applied']);
        $this->assertSame(0, app(LifecycleJobs::class)->housekeeping()['moves_applied']);
        $rows = Assignment::where('employee_id', $emp->id)->orderBy('effective_from')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('2026-10-19', $rows[0]->effective_to->toDateString()); // history kept, effective-dated
        $this->assertSame('2026-10-20', $rows[1]->effective_from->toDateString());
        $this->assertSame($this->org['designation'][1], $rows[1]->designation_id);
        $this->assertSame('promotion', $rows[1]->change_type);
        $this->assertDatabaseHas('domain_events', ['type' => 'EmployeePromoted', 'employee_id' => $emp->id]);
        $this->assertDatabaseHas('lifecycle_moves', ['uuid' => $move['uuid'], 'status' => 'applied']);
    }

    public function test_transfer_with_manager_change_applies_immediately_and_previews_impact(): void
    {
        $boss = $this->hire('Bo Boss');
        $emp = $this->hire('Eva Eng');
        $this->context($this->admin->tenant_id);
        $res = $this->postJson('/api/v1/lifecycle/moves', ['employee_uuid' => $emp->uuid, 'kind' => 'transfer', 'changes' => ['department_id' => $this->org['department'][1], 'location_id' => $this->org['location'][1], 'manager_uuid' => $boss->uuid],
            'effective_date' => '2026-10-07'])->assertCreated();
        $modules = collect($res->json('impact'))->pluck('module')->all();
        $this->assertContains('leave', $modules);
        $this->assertContains('attendance', $modules);
        $this->assertContains('approvals', $modules);

        $this->signIn($this->checker)->postJson('/api/v1/lifecycle/moves/'.$res->json('uuid').'/decision', ['decision' => 'approve'])->assertOk()->assertJsonPath('status', 'applied');
        $this->context($this->admin->tenant_id);
        $current = $emp->assignmentOn('2026-10-07');
        $this->assertSame($this->org['department'][1], $current->department_id);
        $this->assertSame($this->org['location'][1], $current->location_id);
        $this->assertSame($boss->id, ReportingRelationship::where('employee_id', $emp->id)->where('type', 'primary')->orderByDesc('effective_from')->value('manager_id'));
        $this->assertDatabaseHas('domain_events', ['type' => 'EmployeeTransferred', 'employee_id' => $emp->id]);
    }

    public function test_rejected_move_changes_nothing_and_cannot_be_decided_twice(): void
    {
        $emp = $this->hire('Eva Eng');
        $this->context($this->admin->tenant_id);
        $uuid = $this->postJson('/api/v1/lifecycle/moves', ['employee_uuid' => $emp->uuid, 'kind' => 'location_change', 'changes' => ['location_id' => $this->org['location'][1]], 'effective_date' => '2026-10-07'])->json('uuid');
        $this->signIn($this->checker)->postJson('/api/v1/lifecycle/moves/'.$uuid.'/decision', ['decision' => 'reject', 'note' => 'Not now'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson('/api/v1/lifecycle/moves/'.$uuid.'/decision', ['decision' => 'approve'])->assertConflict();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Assignment::where('employee_id', $emp->id)->count());
    }
}
