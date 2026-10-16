<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Leakage\LeakageCaseService;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class CostLeakageTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const API = '/api/v1/intelligence/leakage';

    private Employee $mgr;

    private Employee $emp;

    private Employee $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant(); // 2026-10-07 10:00 Asia/Kolkata
        $this->mgr = $this->hire('Meera Rao', ['role' => 'Manager']);
        $this->emp = $this->hire('Arun Kumar', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->other = $this->hire('Omar Diaz', ['role' => 'Team member']);
        $this->signIn($this->admin);
    }


    private ?int $policy = null;

    private function policyId(): int
    {
        return $this->policy ??= DB::table('expense_policies')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'General', 'category' => 'meals', 'currency' => 'INR',
            'minor_units' => 2, 'max_amount_minor' => 99999999, 'receipt_threshold_minor' => 0, 'effective_from' => '2026-01-01', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function day(Employee $e, string $date, int $calculated, int $approved): void
    {
        DB::table('attendance_days')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $e->id, 'attendance_date' => $date, 'status' => 'present',
            'overtime_minutes' => $calculated, 'approved_overtime_minutes' => $approved, 'engine_version' => 'test', 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function claim(Employee $e, string $merchant, string $date, int $amountMinor, string $status = 'approved'): void
    {
        $claim = DB::table('expense_claims')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $e->id, 'created_by' => $this->admin->id, 'policy_id' => $this->policyId(),
            'policy_snapshot' => '{}', 'title' => 'Trip '.$merchant, 'currency' => 'INR', 'minor_units' => 2, 'total_minor' => $amountMinor, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('expense_lines')->insert(['tenant_id' => $this->admin->tenant_id, 'claim_id' => $claim, 'spent_on' => $date, 'merchant' => $merchant, 'description' => 'Meal', 'amount_minor' => $amountMinor, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function userOf(Employee $e): User
    {
        return User::findOrFail(DB::table('people')->where('id', $e->person_id)->value('user_id'));
    }

    public function test_overtime_spike_is_flagged_with_explainable_evidence_and_scan_is_idempotent(): void
    {
        foreach (['2026-07-10', '2026-08-10', '2026-09-10'] as $d) {
            $this->day($this->emp, $d, 60, 60); // small baseline
        }
        foreach (['2026-10-01', '2026-10-02', '2026-10-05', '2026-10-06'] as $d) {
            $this->day($this->emp, $d, 240, 240); // 960 minutes this month
        }
        $res = $this->postJson(self::API.'/scan')->assertOk();
        $this->assertSame(1, $res->json('scan.created'));
        $case = $res->json('items.0');
        $this->assertSame('ot_spike', $case['rule_code']);
        $this->assertNull($case['exposure']['amount']);
        $this->assertGreaterThan(0, $case['exposure']['minutes']);
        $this->assertSame('open', $case['status']);
        $explain = $this->getJson(self::API.'/'.$case['uuid'].'/explain')->assertOk()->json('explain');
        $this->assertTrue($explain['deterministic']);
        $this->assertArrayHasKey('threshold', $explain);

        $again = $this->postJson(self::API.'/scan')->assertOk();
        $this->assertSame(0, $again->json('scan.created'));
        $this->assertSame(1, $again->json('scan.updated'));
        $this->assertSame(1, $again->json('count'));
    }

    public function test_below_threshold_overtime_is_not_flagged(): void
    {
        $this->day($this->emp, '2026-10-01', 60, 60);
        $this->postJson(self::API.'/scan')->assertOk()->assertJsonPath('scan.detected', 0);
    }

    public function test_approved_overtime_above_calculated_is_flagged(): void
    {
        foreach (['2026-10-01', '2026-10-02'] as $d) {
            $this->day($this->emp, $d, 30, 120);
        }
        $items = $this->postJson(self::API.'/scan')->assertOk()->json('items');
        $this->assertSame(['ot_over_calculated'], array_column($items, 'rule_code'));
        $this->assertSame(180, $items[0]['exposure']['minutes']);
    }

    public function test_duplicate_expense_across_claims_is_flagged_with_money_exposure(): void
    {
        $this->claim($this->emp, 'Hotel Saravana', '2026-10-02', 450000);
        $this->claim($this->emp, ' hotel saravana ', '2026-10-02', 450000);
        $this->claim($this->emp, 'Taxi', '2026-10-03', 20000);
        $items = $this->postJson(self::API.'/scan')->assertOk()->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('duplicate_expense', $items[0]['rule_code']);
        $this->assertEquals(4500.0, $items[0]['exposure']['amount']);
        $this->assertSame('INR', $items[0]['exposure']['currency']);
    }

    public function test_draft_and_rejected_claims_do_not_count_as_duplicates(): void
    {
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000, 'approved');
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000, 'draft');
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000, 'rejected');
        $this->postJson(self::API.'/scan')->assertOk()->assertJsonPath('scan.detected', 0);
    }

    public function test_review_lifecycle_requires_reasons_and_never_reopens_a_closed_case_on_rescan(): void
    {
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000);
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000);
        $uuid = $this->postJson(self::API.'/scan')->json('items.0.uuid');

        $this->postJson(self::API."/$uuid/status", ['status' => 'confirmed'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson(self::API."/$uuid/status", ['status' => 'recovered', 'reason' => 'x', 'recovered_amount' => 10])->assertStatus(422);
        $this->postJson(self::API."/$uuid/status", ['status' => 'confirmed', 'reason' => 'Both receipts identical'])->assertOk()->assertJsonPath('status', 'confirmed');
        $this->postJson(self::API."/$uuid/status", ['status' => 'recovered', 'reason' => 'Deducted in October payroll'])->assertStatus(422)->assertJsonValidationErrors('recovered_amount');
        $this->postJson(self::API."/$uuid/status", ['status' => 'recovered', 'reason' => 'Deducted in October payroll', 'recovered_amount' => 100])
            ->assertOk()->assertJsonPath('exposure.recovered_amount', 100);

        $this->postJson(self::API.'/scan')->assertOk()->assertJsonPath('scan.unchanged_closed', 1)->assertJsonPath('scan.created', 0);
        $this->getJson(self::API."/$uuid")->assertJsonPath('status', 'recovered');
        $this->postJson(self::API."/$uuid/status", ['status' => 'open', 'reason' => 'oops'])->assertStatus(422);
        $this->assertGreaterThanOrEqual(3, count($this->getJson(self::API."/$uuid")->json('history')));
    }

    public function test_assigning_creates_one_task_and_moves_case_under_review(): void
    {
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000);
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000);
        $uuid = $this->postJson(self::API.'/scan')->json('items.0.uuid');
        $this->postJson(self::API."/$uuid/assign", ['owner_user_id' => $this->admin->id])->assertOk()->assertJsonPath('created_task', true)->assertJsonPath('case.status', 'under_review');
        $this->postJson(self::API."/$uuid/assign", ['owner_user_id' => $this->admin->id])->assertOk()->assertJsonPath('created_task', false);
        $this->assertSame(1, DB::table('work_items')->where('source_module', 'intelligence')->where('source_entity', 'leakage:'.$uuid)->count());
        $this->postJson(self::API."/$uuid/assign", ['owner_user_id' => $this->userOf($this->emp)->id])->assertStatus(422);
    }

    public function test_only_users_with_the_permission_can_open_or_scan(): void
    {
        $this->signIn($this->userOf($this->mgr));
        $this->getJson(self::API)->assertForbidden();
        $this->postJson(self::API.'/scan')->assertForbidden();
        $this->signIn($this->userOf($this->emp));
        $this->getJson(self::API)->assertForbidden();
    }

    public function test_cases_are_limited_to_the_viewers_data_scope(): void
    {
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000);
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000);
        $this->postJson(self::API.'/scan')->assertOk();
        // A scoped viewer holding the permission only sees their reach.
        $role = DB::table('roles')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'name' => 'Cost reviewer', 'description' => 'x', 'data_scope' => 'self', 'system' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['intelligence.leakage.view', 'intelligence.leakage.manage'] as $key) {
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('key', $key)->value('id')]);
        }
        $viewer = $this->userOf($this->other);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $viewer->id]);
        $this->signIn($viewer);
        $this->getJson(self::API)->assertOk()->assertJsonPath('count', 0);
        $this->postJson(self::API.'/scan')->assertOk()->assertJsonPath('scan.detected', 0);
    }

    public function test_settings_override_thresholds_and_can_disable_rules(): void
    {
        foreach (['2026-10-01', '2026-10-02'] as $d) {
            $this->day($this->emp, $d, 30, 120);
        }
        $this->putJson(self::API.'/settings', ['thresholds' => ['ot_over_calculated_min_minutes' => 600]])->assertOk();
        $this->postJson(self::API.'/scan')->assertJsonPath('scan.detected', 0);
        $this->putJson(self::API.'/settings', ['thresholds' => ['ot_over_calculated_min_minutes' => 60], 'disabled_rules' => ['ot_over_calculated']])->assertOk();
        $this->postJson(self::API.'/scan')->assertJsonPath('scan.detected', 0);
        $this->putJson(self::API.'/settings', ['thresholds' => ['ot_over_calculated_min_minutes' => 60], 'disabled_rules' => []])->assertOk();
        $this->postJson(self::API.'/scan')->assertJsonPath('scan.detected', 1);
        $this->putJson(self::API.'/settings', ['thresholds' => ['ot_spike_ratio' => 0.5]])->assertStatus(422);
    }

    public function test_cases_are_tenant_isolated(): void
    {
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000);
        $this->claim($this->emp, 'Cafe', '2026-10-02', 10000);
        $uuid = $this->postJson(self::API.'/scan')->json('items.0.uuid');
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson(self::API)->assertOk()->assertJsonPath('count', 0);
        $this->getJson(self::API."/$uuid")->assertNotFound();
    }

    public function test_status_catalogue_is_consistent(): void
    {
        $this->assertSame(LeakageCaseService::STATUSES, array_keys(LeakageCaseService::TRANSITIONS));
    }
}
