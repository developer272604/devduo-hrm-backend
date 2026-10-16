<?php

namespace Tests\Feature\Intelligence;

use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class WorkforcePlanScenarioTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const API = '/api/v1/intelligence/scenarios';

    private Employee $mgr;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->mgr = $this->hire('Meera Rao', ['role' => 'Manager']);
        $this->emp = $this->hire('Arun Kumar', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->signIn($this->admin);
    }

    private function params(array $over = []): array
    {
        return ['kind' => 'workforce_plan', 'title' => 'Q1 hiring', 'params' => $over + ['months' => 6, 'annual_attrition_pct' => 12, 'avg_monthly_cost' => 50000, 'currency' => 'INR',
            'alternatives' => [['name' => 'Do nothing'], ['name' => 'Hire 5', 'waves' => [['month' => 1, 'count' => 5, 'lead_time_months' => 2]]]]]];
    }

    private function userOf(Employee $e): User
    {
        return User::findOrFail(DB::table('people')->where('id', $e->person_id)->value('user_id'));
    }

    public function test_plan_runs_with_scoped_baseline_and_is_stored_as_a_read_only_scenario(): void
    {
        $working = DB::table('employees')->where('tenant_id', $this->admin->tenant_id)->whereNull('deleted_at')->whereIn('status', Employee::WORKING)->count();
        $res = $this->postJson(self::API, $this->params())->assertCreated();
        $this->assertTrue($res->json('read_only'));
        $this->assertTrue($res->json('writes_rolled_back'));
        $this->assertSame($working, $res->json('result.baseline_headcount'));
        $this->assertCount(2, $res->json('result.alternatives'));
        $this->assertNotNull($res->json('result.alternatives.1.vs_first'));
        $this->assertStringContainsString('not a forecast', $res->json('result.limitations'));
        $this->getJson(self::API.'/'.$res->json('uuid'))->assertOk()->assertJsonPath('title', 'Q1 hiring');
    }

    public function test_validation_errors_are_returned_and_nothing_is_stored(): void
    {
        $before = DB::table('intelligence_scenario_runs')->count();
        $this->postJson(self::API, $this->params(['months' => 99]))->assertStatus(422)->assertJsonValidationErrors('months');
        $this->postJson(self::API, $this->params(['alternatives' => []]))->assertStatus(422);
        $this->assertSame($before, DB::table('intelligence_scenario_runs')->count());
    }

    public function test_baseline_is_limited_to_the_viewers_data_scope(): void
    {
        $this->hire('Omar Diaz', ['role' => 'Team member']); // outside the manager's reach
        $this->signIn($this->userOf($this->mgr)); // Manager: direct reports only
        $res = $this->postJson(self::API, $this->params())->assertCreated();
        // Scope = the manager's own record plus direct reports (Arun); never the other employees.
        $this->assertSame(2, $res->json('result.baseline_headcount'));
    }

    public function test_users_without_the_scenario_permission_are_refused(): void
    {
        $this->signIn($this->userOf($this->emp));
        $this->postJson(self::API, $this->params())->assertForbidden();
    }
}
