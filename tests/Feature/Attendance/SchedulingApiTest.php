<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Scheduling\AssignmentResolver;
use App\Domain\Attendance\Scheduling\Specificity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class SchedulingApiTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->signIn($this->admin);
    }

    public function test_specificity_ranking_and_pure_pick(): void
    {
        $this->assertSame(100, Specificity::rank('employee'));
        $this->assertGreaterThan(Specificity::rank('department'), Specificity::rank('team'));
        $rows = [
            ['id' => 1, 'target_type' => 'tenant', 'target_id' => '*', 'effective_from' => '2026-01-01', 'effective_to' => null, 'priority' => 9, 'is_override' => 1],
            ['id' => 2, 'target_type' => 'location', 'target_id' => '5', 'effective_from' => '2026-01-01', 'effective_to' => null, 'priority' => 0, 'is_override' => 0],
            ['id' => 3, 'target_type' => 'location', 'target_id' => '5', 'effective_from' => '2026-03-01', 'effective_to' => null, 'priority' => 0, 'is_override' => 0],
        ];
        $ctx = ['location' => '5', 'employee' => '1'];
        $this->assertSame(3, AssignmentResolver::pick($rows, $ctx, '2026-04-01')['row']['id']);
        $this->assertSame(2, AssignmentResolver::pick($rows, $ctx, '2026-02-01')['row']['id']);
    }

    public function test_shift_crud_in_use_protection_and_validation_messages(): void
    {
        $res = $this->postJson('/api/v1/shifts', ['name' => 'Gen', 'code' => 'gen', 'timezone' => 'Asia/Kolkata', 'start_time' => '09:00', 'end_time' => '18:00'])->assertCreated();
        $uuid = $res->json('data.uuid');
        $this->assertSame('GEN', $res->json('data.code'));
        $this->postJson('/api/v1/shifts', ['name' => 'Dup', 'code' => 'GEN', 'timezone' => 'Asia/Kolkata', 'start_time' => '09:00', 'end_time' => '18:00'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/shift-assignments', ['target_type' => 'tenant', 'shift_uuid' => $uuid, 'effective_from' => '2026-01-01'])->assertCreated();
        $this->deleteJson("/api/v1/shifts/$uuid")->assertStatus(409)->assertJsonPath('code', 'shift_in_use');
        $this->postJson('/api/v1/shift-assignments', ['target_type' => 'tenant', 'shift_uuid' => $uuid, 'effective_from' => '2026-05-01'])->assertStatus(422)->assertJsonPath('code', 'ambiguous_assignment');
        $this->patchJson("/api/v1/shifts/$uuid", ['start_time' => '10:00', 'end_time' => '19:00'])->assertStatus(409);
        $this->patchJson("/api/v1/shifts/$uuid", ['start_time' => '10:00', 'end_time' => '19:00', 'confirm_in_use_change' => true])->assertOk();
    }

    public function test_impact_preview_my_schedule_and_compliance_settings(): void
    {
        $e = $this->hire('Asha Rao', ['role' => 'Team member']);
        $shift = $this->postJson('/api/v1/shifts', ['name' => 'Gen', 'code' => 'GEN', 'timezone' => 'Asia/Kolkata', 'start_time' => '09:00', 'end_time' => '18:00'])->json('data.uuid');
        $this->context($this->admin->tenant_id);
        $preview = $this->postJson('/api/v1/attendance/impact-preview', ['type' => 'shift_assignment', 'change' => ['target_type' => 'department', 'target_id' => $this->org['department'][0], 'effective_from' => '2026-10-01']])
            ->assertOk()->json('data');
        $this->assertSame(1, $preview['affected_employees']);
        $this->assertSame(0, \App\Domain\Attendance\Models\ShiftAssignment::count());
        $this->assertStringContainsString('recalculate attendance for', $preview['message']);

        $this->postJson('/api/v1/shift-assignments', ['target_type' => 'employee', 'target_id' => $e->uuid, 'shift_uuid' => $shift, 'effective_from' => '2026-01-01'])->assertCreated();
        $user = \App\Models\User::find(\DB::table('people')->where('id', $e->person_id)->value('user_id'));
        $this->signIn($user);
        $mine = $this->getJson('/api/v1/attendance/me/schedule?from=2026-10-07&to=2026-10-13')->assertOk()->json('data');
        $this->assertCount(7, $mine['days']);
        $this->assertSame('GEN', $mine['today']['shift']['code']);
        $this->assertSame('09:00', $mine['today']['shift']['start']);
        $this->assertNotNull($mine['next']);

        $this->signIn($this->admin);
        $this->putJson('/api/v1/attendance/compliance', ['enforcement' => 'block', 'countries' => ['IN' => ['rules' => ['max_daily_minutes' => 600]]]])->assertOk()->assertJsonPath('data.settings.enforcement', 'block');
        $this->getJson('/api/v1/attendance/compliance')->assertOk()->assertJsonPath('data.countries.IN.effective.max_daily_minutes', 600)->assertJsonPath('data.countries.IN.pack.max_daily_minutes', 540);
        $this->putJson('/api/v1/attendance/compliance', ['enforcement' => 'nope'])->assertStatus(422);
    }
}
