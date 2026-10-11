<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Cycles\CycleCalculator;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Policies\PolicyResolver;
use App\Domain\Leave\Policies\PolicyService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\AssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class PolicyResolverTest extends PolicyTestCase
{
    use RefreshDatabase;

    private Employee $asha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->signIn($this->admin);
        $this->asha = $this->hire('Asha Rao', ['joined_on' => '2026-03-01']);
        LeaveType::create(['name' => 'Annual', 'code' => 'AL', 'kind' => 'annual']);
        LeaveType::create(['name' => 'Sick', 'code' => 'SL', 'kind' => 'sick']);
    }

    /** Creates + publishes a policy granting the given type codes, and returns its uuid. */
    private function publishedPolicy(string $code, array $types = ['AL'], array $rules = [], string $from = '2026-01-01'): string
    {
        $uuid = $this->postJson('/api/v1/leave/policies', ['name' => $code, 'code' => $code, 'effective_from' => $from,
            'type_rules' => array_map(fn ($t) => ['type' => $t, 'rules' => $rules + ['entitlement' => ['basis' => 'annual', 'amount' => '10']]], $types)])->assertCreated()->json('data.uuid');
        $this->postJson("/api/v1/leave/policies/$uuid/versions/1/publish")->assertOk();

        return $uuid;
    }

    private function assign(string $policy, array $o = []): array
    {
        return $this->postJson('/api/v1/leave/policy-assignments', $o + ['policy' => $policy, 'target_type' => 'tenant', 'effective_from' => '2026-01-01'])->assertCreated()->json('data');
    }

    private function resolve(string $date, ?string $type = null, ?Employee $e = null)
    {
        return $this->getJson('/api/v1/leave/policies/resolve?employee='.($e ?? $this->asha)->uuid.'&date='.$date.($type ? '&leave_type='.$type : ''));
    }

    public function test_contract_is_bound_to_the_resolver(): void
    {
        $this->assertInstanceOf(PolicyResolver::class, app(LeavePolicyResolver::class));
        $this->assertNull(app(LeavePolicyResolver::class)->resolve($this->asha->id, '2026-10-07'));
    }

    public function test_precedence_is_deterministic_specific_beats_general(): void
    {
        $tenant = $this->publishedPolicy('TENANT');
        $country = $this->publishedPolicy('COUNTRY');
        $dept = $this->publishedPolicy('DEPT');
        $own = $this->publishedPolicy('OWN');
        $this->assign($tenant);
        $this->assertSame('TENANT', $this->resolve('2026-10-07')->json('data.policy.name'));
        $this->assign($country, ['target_type' => 'country', 'target_id' => 'in']);
        $this->assertSame('COUNTRY', $this->resolve('2026-10-07')->json('data.policy.name'));
        $this->assign($dept, ['target_type' => 'department', 'target_id' => $this->org['department'][0]]);
        $res = $this->resolve('2026-10-07')->assertOk()->json('data');
        $this->assertSame('DEPT', $res['policy']['name']);
        $this->assertSame('department', $res['matched_by']);
        $this->assertSame(['selected', 'not_used', 'not_used'], array_column($res['considered'], 'outcome'));
        $this->assign($own, ['target_type' => 'employee', 'target_id' => $this->asha->uuid]);
        $this->assertSame('OWN', $this->resolve('2026-10-07')->json('data.policy.name'));
        // a broad override never beats a more specific assignment, but beats an equal-rank one with higher priority
        $this->assign($tenant, ['target_type' => 'department', 'target_id' => $this->org['department'][0], 'is_override' => true]);
        $this->assertSame('OWN', $this->resolve('2026-10-07')->json('data.policy.name'));
        $other = $this->hire('Ben Ford', ['department' => 0]);
        $this->assertSame('TENANT', $this->resolve('2026-10-07', null, $other)->json('data.policy.name'), 'override outranks the plain department assignment');
    }

    public function test_same_rank_priority_wins_and_equal_rank_assignments_are_rejected_as_ambiguous(): void
    {
        $a = $this->publishedPolicy('A');
        $b = $this->publishedPolicy('B');
        $this->assign($a);
        $this->postJson('/api/v1/leave/policy-assignments', ['policy' => $b, 'target_type' => 'tenant', 'effective_from' => '2026-06-01'])->assertUnprocessable()->assertJsonValidationErrors('target_id');
        $this->assign($b, ['priority' => 5]);
        $this->assertSame('B', $this->resolve('2026-10-07')->json('data.policy.name'));
        $this->postJson('/api/v1/leave/policy-assignments', ['policy' => $a, 'target_type' => 'galaxy', 'effective_from' => '2026-01-01'])->assertUnprocessable();
        $this->postJson('/api/v1/leave/policy-assignments', ['policy' => $a, 'target_type' => 'department', 'target_id' => 99999, 'effective_from' => '2026-01-01'])->assertUnprocessable();
    }

    public function test_org_attributes_are_as_of_the_date_after_a_transfer(): void
    {
        $eng = $this->publishedPolicy('ENG');
        $sal = $this->publishedPolicy('SAL');
        $this->assign($eng, ['target_type' => 'department', 'target_id' => $this->org['department'][0]]);
        $this->assign($sal, ['target_type' => 'department', 'target_id' => $this->org['department'][1]]);
        app(AssignmentService::class)->change($this->asha, ['department_id' => $this->org['department'][1]], '2026-10-15');
        $this->assertSame('ENG', $this->resolve('2026-10-14')->json('data.policy.name'));
        $this->assertSame('SAL', $this->resolve('2026-10-15')->json('data.policy.name'));
    }

    public function test_effective_dates_policy_versions_and_inactive_policies(): void
    {
        $p = $this->publishedPolicy('STD');
        $this->assign($p, ['effective_from' => '2026-02-01', 'effective_to' => '2026-12-31']);
        $this->assertNull($this->resolve('2026-01-31')->json('data.policy'));
        $this->assertNotNull($this->resolve('2026-02-01')->json('data.policy'));
        $this->assertNull($this->resolve('2027-01-01')->json('data.policy'));
        $this->assertSame('No leave policy applies to this employee on this date.', $this->resolve('2027-01-01')->json('data.explanation'));
        // v2 from December: January-November history keeps resolving to version 1
        $this->postJson("/api/v1/leave/policies/$p/versions", ['effective_from' => '2026-12-01', 'change_note' => 'v2'])->assertCreated();
        $this->postJson("/api/v1/leave/policies/$p/versions/2/publish")->assertOk();
        $this->assertSame(1, $this->resolve('2026-11-30')->json('data.policy.version'));
        $this->assertSame(2, $this->resolve('2026-12-01')->json('data.policy.version'));
        $this->patchJson("/api/v1/leave/policies/$p", ['active' => false])->assertOk();
        $res = $this->resolve('2026-10-07')->json('data');
        $this->assertNull($res['policy']);
        $this->assertSame('skipped', $res['considered'][0]['outcome']);
        $this->postJson('/api/v1/leave/policy-assignments', ['policy' => $p, 'target_type' => 'tenant', 'effective_from' => '2027-02-01'])->assertUnprocessable();
    }

    public function test_rules_for_merges_policy_and_type_rules_and_returns_null_when_not_granted(): void
    {
        $uuid = $this->postJson('/api/v1/leave/policies', ['name' => 'P', 'code' => 'P', 'effective_from' => '2026-01-01', 'rules' => ['notice_days' => 2, 'sandwich' => ['enabled' => true]],
            'type_rules' => [['type' => 'AL', 'rules' => ['notice_days' => 5, 'entitlement' => ['basis' => 'annual', 'amount' => '18.5']]]]])->json('data.uuid');
        $this->postJson("/api/v1/leave/policies/$uuid/versions/1/publish")->assertOk();
        $this->assign($uuid);
        $al = LeaveType::query()->where('code', 'AL')->first();
        $sl = LeaveType::query()->where('code', 'SL')->first();
        $r = app(LeavePolicyResolver::class)->rulesFor($this->asha->id, $al->id, '2026-10-07');
        $this->assertSame(5, $r['rules']['notice_days'], 'type rule overrides the policy-wide value');
        $this->assertTrue($r['rules']['sandwich']['enabled'], 'policy-wide rules are inherited');
        $this->assertSame('18.5', $r['rules']['entitlement']['amount']);
        $this->assertSame($al->id, $r['type_id']);
        $this->assertNull(app(LeavePolicyResolver::class)->rulesFor($this->asha->id, $sl->id, '2026-10-07'));
        $this->assertFalse($this->resolve('2026-10-07', 'SL')->json('data.leave_type.granted'));
        $this->assertTrue($this->resolve('2026-10-07', 'AL')->json('data.leave_type.granted'));
        $al->update(['active' => false]);
        $this->assertNull(app(LeavePolicyResolver::class)->rulesFor($this->asha->id, $al->id, '2026-10-07'));
    }

    public function test_applicability_by_country_entity_and_type_is_data_not_code(): void
    {
        $uuid = $this->postJson('/api/v1/leave/policies', ['name' => 'Global', 'code' => 'G', 'effective_from' => '2026-01-01', 'type_rules' => [
            ['type' => 'AL', 'rules' => ['eligibility' => ['countries' => ['GB']]]],
            ['type' => 'SL', 'rules' => ['eligibility' => ['countries' => ['IN'], 'legal_entities' => [(string) $this->org['legal_entity'][0]]]]],
        ]])->json('data.uuid');
        $this->postJson("/api/v1/leave/policies/$uuid/versions/1/publish")->assertOk();
        $this->assign($uuid);
        $uk = $this->hire('Ben Ford', ['location' => 1]);
        $this->assertFalse($this->resolve('2026-10-07', 'AL')->json('data.leave_type.granted'));
        $this->assertTrue($this->resolve('2026-10-07', 'SL')->json('data.leave_type.granted'));
        $this->assertTrue($this->resolve('2026-10-07', 'AL', $uk)->json('data.leave_type.granted'));
        $this->assertFalse($this->resolve('2026-10-07', 'SL', $uk)->json('data.leave_type.granted'));
        $this->assertStringContainsString('country', $this->resolve('2026-10-07', 'SL', $uk)->json('data.leave_type.reason'));
    }

    public function test_time_based_eligibility_probation_tenure_and_notice(): void
    {
        $uuid = $this->postJson('/api/v1/leave/policies', ['name' => 'P', 'code' => 'P', 'effective_from' => '2026-01-01', 'type_rules' => [
            ['type' => 'AL', 'rules' => ['eligibility' => ['probation_days' => 90, 'min_tenure_days' => 120]]],
            ['type' => 'SL', 'rules' => ['eligibility' => ['block_during_notice' => true]]],
        ]])->json('data.uuid');
        $this->postJson("/api/v1/leave/policies/$uuid/versions/1/publish")->assertOk();
        $this->assign($uuid);
        // joined 2026-03-01: probation passes 2026-05-30, tenure 2026-06-29
        $early = $this->getJson('/api/v1/leave/eligibility/check?employee='.$this->asha->uuid.'&leave_type=AL&date=2026-05-01')->assertOk()->json('data');
        $this->assertTrue($early['granted']);
        $this->assertFalse($early['eligibility']['eligible']);
        $this->assertSame('2026-06-29', $early['eligibility']['eligible_from']);
        $this->assertCount(2, $early['eligibility']['reasons']);
        $this->assertTrue($this->getJson('/api/v1/leave/eligibility/check?employee='.$this->asha->uuid.'&leave_type=AL&date=2026-06-29')->json('data.eligibility.eligible'));
        $this->assertTrue($this->getJson('/api/v1/leave/eligibility/check?employee='.$this->asha->uuid.'&leave_type=SL&date=2026-10-07')->json('data.eligibility.eligible'));
        \Illuminate\Support\Facades\DB::table('employees')->where('id', $this->asha->id)->update(['separated_on' => '2026-10-31']);
        $this->assertFalse($this->getJson('/api/v1/leave/eligibility/check?employee='.$this->asha->uuid.'&leave_type=SL&date=2026-10-07')->json('data.eligibility.eligible'));
    }

    public function test_resolve_respects_data_scope_and_tenant(): void
    {
        $this->assign($this->publishedPolicy('STD'));
        $boss = $this->hire('Maya Boss', ['role' => 'Manager', 'email' => 'maya@alpha.test']);
        $mine = $this->hire('Dev Report', ['manager' => $boss]);
        $this->hire('Zed Peer', ['role' => 'Team member', 'email' => 'zed@alpha.test']);
        $this->signIn(\App\Models\User::query()->where('email', 'zed@alpha.test')->first());
        $this->resolve('2026-10-07', null, $mine)->assertNotFound();
        $this->getJson('/api/v1/leave/eligibility/check?employee='.$mine->uuid.'&leave_type=AL&date=2026-10-07')->assertNotFound();
        $this->signIn(\App\Models\User::query()->where('email', 'maya@alpha.test')->first());
        $this->resolve('2026-10-07', null, $mine)->assertOk();
        $other = \App\Domain\Access\WorkspaceProvisioner::class;
        $this->signIn(app($other)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123'));
        $this->resolve('2026-10-07', null, $mine)->assertNotFound();
        $this->assertSame(0, LeavePolicy::query()->count());
    }

    public function test_cycle_calculator_calendar_fiscal_anniversary_custom(): void
    {
        $cal = ['kind' => 'calendar', 'start_month' => 1, 'start_day' => 1, 'length_months' => 12];
        $this->assertSame(['start' => '2026-01-01', 'end' => '2026-12-31', 'label' => '2026'], CycleCalculator::periodFor($cal, '2026-10-07'));
        $fy = ['kind' => 'fiscal', 'start_month' => 4, 'start_day' => 1, 'length_months' => 12];
        $this->assertSame(['start' => '2026-04-01', 'end' => '2027-03-31', 'label' => 'FY 2026-27'], CycleCalculator::periodFor($fy, '2027-03-31'));
        $this->assertSame('2025-04-01', CycleCalculator::periodFor($fy, '2026-03-31')['start']);
        $an = ['kind' => 'anniversary', 'start_month' => 1, 'start_day' => 1, 'length_months' => 12];
        $this->assertSame(['2026-03-15', '2027-03-14'], array_values(array_diff_key(CycleCalculator::periodFor($an, '2026-10-07', '2024-03-15'), ['label' => 1])));
        $this->assertSame('2026-02-28', CycleCalculator::periodFor($an, '2026-03-01', '2024-02-29')['start'], 'leap-day anchors clamp to month end');
        $half = ['kind' => 'custom', 'start_month' => 7, 'start_day' => 1, 'length_months' => 6];
        $this->assertSame(['2026-07-01', '2026-12-31'], array_values(array_diff_key(CycleCalculator::periodFor($half, '2026-10-07'), ['label' => 1])));
        $this->assertSame(['2026-01-01', '2026-06-30'], array_values(array_diff_key(CycleCalculator::periodFor($half, '2026-02-10'), ['label' => 1])));
        $this->assertCount(3, CycleCalculator::periodsBetween($half, '2026-02-01', '2027-02-01'));
        $this->assertInstanceOf(PolicyService::class, app(PolicyService::class));
    }
}
