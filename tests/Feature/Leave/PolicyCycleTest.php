<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Models\LeaveCyclePeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class PolicyCycleTest extends PolicyTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->signIn($this->admin);
    }

    private function cycle(array $o = []): array
    {
        return $this->postJson('/api/v1/leave/cycles', $o + ['name' => 'Calendar', 'code' => 'CAL', 'kind' => 'calendar'])->assertCreated()->json('data');
    }

    public function test_cycle_crud_and_kind_normalisation(): void
    {
        $c = $this->cycle(['start_month' => 6, 'length_months' => 3]); // calendar ignores start/length
        $this->assertSame([1, 1, 12], [$c['start_month'], $c['start_day'], $c['length_months']]);
        $fy = $this->cycle(['name' => 'FY', 'code' => 'FY', 'kind' => 'fiscal', 'start_month' => 4]);
        $this->assertSame(12, $fy['length_months']);
        $this->postJson('/api/v1/leave/cycles', ['name' => 'X', 'code' => 'FY'])->assertUnprocessable();
        $this->postJson('/api/v1/leave/cycles', ['name' => 'X', 'code' => 'X', 'kind' => 'weekly'])->assertUnprocessable();
        $this->postJson('/api/v1/leave/cycles', ['name' => 'X', 'code' => 'X', 'kind' => 'custom', 'start_month' => 2, 'start_day' => 30])->assertUnprocessable()->assertJsonValidationErrors('start_day');
        $this->postJson('/api/v1/leave/cycles', ['name' => 'X', 'code' => 'X', 'kind' => 'custom', 'length_months' => 0])->assertUnprocessable();
        $this->patchJson('/api/v1/leave/cycles/'.$fy['uuid'], ['name' => 'Financial year', 'start_month' => 7])->assertOk()->assertJsonPath('data.start_month', 7);
        $this->patchJson('/api/v1/leave/cycles/'.$fy['uuid'], ['code' => 'NEW'])->assertUnprocessable(); // code is create-only
        $this->getJson('/api/v1/leave/cycles')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_period_generation_is_idempotent_and_freezes_the_cycle_shape(): void
    {
        $fy = $this->cycle(['name' => 'FY', 'code' => 'FY', 'kind' => 'fiscal', 'start_month' => 4]);
        $g = $this->postJson("/api/v1/leave/cycles/{$fy['uuid']}/periods/generate", ['from' => '2025-04-01', 'to' => '2027-03-31'])->assertOk()->json('data');
        $this->assertSame(2, $g['created']);
        $this->assertSame(['FY 2025-26', 'FY 2026-27'], array_column($g['periods'], 'label'));
        $again = $this->postJson("/api/v1/leave/cycles/{$fy['uuid']}/periods/generate", ['from' => '2025-04-01', 'to' => '2028-03-31'])->assertOk()->json('data');
        $this->assertSame([1, 2], [$again['created'], $again['existing']]);
        LeaveCyclePeriod::query()->where('label', 'FY 2025-26')->update(['status' => 'closed']);
        $this->postJson("/api/v1/leave/cycles/{$fy['uuid']}/periods/generate", ['from' => '2025-04-01', 'to' => '2026-03-31'])->assertOk();
        $this->assertSame('closed', LeaveCyclePeriod::query()->where('label', 'FY 2025-26')->value('status'), 'generation never reopens a closed period');
        $this->getJson("/api/v1/leave/cycles/{$fy['uuid']}/periods")->assertOk()->assertJsonCount(3, 'data');
        $this->patchJson('/api/v1/leave/cycles/'.$fy['uuid'], ['start_month' => 1])->assertUnprocessable();
        $this->patchJson('/api/v1/leave/cycles/'.$fy['uuid'], ['active' => false])->assertOk();
        $this->postJson("/api/v1/leave/cycles/{$fy['uuid']}/periods/generate", ['from' => '2027-01-01', 'to' => '2026-01-01'])->assertUnprocessable();
    }

    public function test_anniversary_cycles_are_per_employee(): void
    {
        $an = $this->cycle(['name' => 'Anniv', 'code' => 'ANV', 'kind' => 'anniversary']);
        $e = $this->hire('Asha Rao', ['joined_on' => '2024-03-15']);
        $this->postJson("/api/v1/leave/cycles/{$an['uuid']}/periods/generate", ['from' => '2026-01-01', 'to' => '2026-12-31'])->assertUnprocessable();
        $this->getJson("/api/v1/leave/cycles/{$an['uuid']}/period-for?date=2026-10-07")->assertUnprocessable();
        $p = $this->getJson("/api/v1/leave/cycles/{$an['uuid']}/period-for?date=2026-10-07&employee={$e->uuid}")->assertOk()->json('data');
        $this->assertSame(['2026-03-15', '2027-03-14'], [$p['start'], $p['end']]);
        $this->getJson("/api/v1/leave/cycles/{$an['uuid']}/period-for?date=2026-02-01&anchor=2024-03-15")->assertOk()->assertJsonPath('data.start', '2025-03-15');
    }

    public function test_cycles_need_the_policy_permission_and_stay_in_the_tenant(): void
    {
        $c = $this->cycle();
        $this->hire('Asha Rao', ['role' => 'Team member', 'email' => 'asha@alpha.test']);
        $this->signIn(\App\Models\User::query()->where('email', 'asha@alpha.test')->first());
        $this->getJson('/api/v1/leave/cycles')->assertForbidden();
        $this->postJson('/api/v1/leave/cycles', ['name' => 'X', 'code' => 'X'])->assertForbidden();
        $this->signIn(app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123'));
        $this->getJson('/api/v1/leave/cycles/'.$c['uuid'])->assertNotFound();
        $this->getJson('/api/v1/leave/cycles/'.$c['uuid'].'/periods')->assertNotFound();
    }
}
