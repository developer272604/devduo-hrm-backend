<?php

namespace Tests\Feature\Intelligence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

class ValueLedgerTest extends PerformanceTestCase
{
    private const V = '/api/v1/intelligence/value/';

    private function recoveredCase(string $closed, float $recovered, string $status = 'recovered', float $exposure = 1000): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('intelligence_leakage_cases')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'fingerprint' => Str::random(40), 'rule_code' => 'duplicate_expense', 'severity' => 'warning',
            'window_from' => '2026-08-01', 'window_to' => '2026-08-31', 'title' => 'Duplicate claim', 'exposure_amount' => $exposure, 'currency' => 'INR', 'evidence' => '{}', 'explain' => '{}', 'status' => $status,
            'recovered_amount' => $status === 'recovered' ? $recovered : null, 'first_seen_at' => $closed, 'last_seen_at' => $closed, 'closed_at' => $status === 'recovered' ? $closed : null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function workflow(): array
    {
        $this->signIn($this->admin);
        $d = $this->postJson('/api/v1/workflows/definitions', ['name' => 'Joiner setup', 'spec' => ['steps' => [['title' => 'Review', 'action' => 'task', 'assignment' => 'fixed', 'assignees' => [$this->checker->id], 'mode' => 'all', 'timeout_hours' => 24]]]])->assertCreated()->json();
        $this->signIn($this->checker);
        $this->postJson('/api/v1/workflows/definitions/'.$d['uuid'].'/publish', ['revision' => $d['revision']])->assertOk();
        $this->signIn($this->admin);

        return $d;
    }

    private function wfRun(array $def, string $status, string $at): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $defId = DB::table('workflow_definitions')->where('uuid', $def['uuid'])->value('id');
        DB::table('workflow_instances')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'definition_id' => $defId, 'version_id' => DB::table('workflow_versions')->where('definition_id', $defId)->value('id'),
            'employee_id' => $this->emp1->id, 'initiated_by' => $this->admin->id, 'idempotency_key' => Str::random(20), 'status' => $status, 'context' => '{}', 'created_at' => $at, 'updated_at' => $at]);
    }

    private function report(string $from = '2026-08', string $to = '2026-09')
    {
        return $this->getJson(self::V."report?from=$from&to=$to");
    }

    public function test_verified_recoveries_are_counted_by_month_and_pending_is_shown_separately(): void
    {
        $this->recoveredCase('2026-09-10 10:00:00', 3000);
        $this->recoveredCase('2026-09-20 10:00:00', 1500);
        $this->recoveredCase('2026-08-05 10:00:00', 200);
        $this->recoveredCase('2026-09-01 10:00:00', 0, 'confirmed', 800); // identified, not yet recovered
        $r = $this->report()->assertOk();
        $this->assertEquals(['INR' => 4700.0], $r->json('totals.verified_recovered'));
        $this->assertEquals(['INR' => 800.0], $r->json('totals.identified_not_yet_recovered'));
        $this->assertEquals(200.0, $r->json('months.0.verified_recovered.INR'));
        $this->assertEquals(4500.0, $r->json('months.1.verified_recovered.INR'));
        $this->assertCount(3, $r->json('verified_cases'));
        $this->assertStringContainsString('never added together', $r->json('confidence_note'));
    }

    public function test_automation_time_saved_is_an_estimate_from_a_written_assumption_and_completed_runs_only(): void
    {
        $def = $this->workflow();
        foreach (range(1, 6) as $i) {
            $this->wfRun($def, 'completed', '2026-09-0'.$i.' 10:00:00');
        }
        $this->wfRun($def, 'running', '2026-09-08 10:00:00'); // not completed
        $this->wfRun($def, 'completed', '2026-07-15 10:00:00'); // outside the range
        $this->assertSame(0.0, (float) $this->report()->json('totals.estimated_hours_saved')); // no assumption yet: nothing is estimated

        $this->putJson(self::V."assumptions/{$def['uuid']}", ['minutes_per_run' => 45, 'basis' => 'short'])->assertStatus(422)->assertJsonValidationErrors('basis');
        $this->putJson(self::V."assumptions/{$def['uuid']}", ['minutes_per_run' => 45, 'hourly_cost' => 400, 'basis' => 'Timed five recent manual onboardings'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->putJson(self::V."assumptions/{$def['uuid']}", ['minutes_per_run' => 45, 'hourly_cost' => 400, 'currency' => 'inr', 'basis' => 'Timed five recent manual onboardings'])->assertOk();
        $r = $this->report()->assertOk();
        $this->assertEquals(4.5, $r->json('totals.estimated_hours_saved')); // 6 x 45 min
        $this->assertEquals(['INR' => 1800.0], $r->json('totals.estimated_value'));
        $this->assertSame(6, $r->json('estimates.0.completed_runs'));
        $this->assertSame([], $r->json('totals.verified_recovered')); // estimates never leak into verified
        $this->deleteJson(self::V."assumptions/{$def['uuid']}")->assertNoContent();
        $this->assertSame(0.0, (float) $this->report()->json('totals.estimated_hours_saved'));
    }

    public function test_manual_claims_need_a_basis_and_stay_labelled_as_claimed(): void
    {
        $post = fn (array $o) => $this->postJson(self::V.'entries', $o + ['month' => '2026-09', 'category' => 'avoided_cost', 'title' => 'Avoided agency fee']);
        $post(['basis' => 'too short', 'amount' => 5000, 'currency' => 'INR'])->assertStatus(422)->assertJsonValidationErrors('basis');
        $post(['basis' => 'Quote from the agency vs hiring internally', 'amount' => 5000])->assertStatus(422)->assertJsonValidationErrors('currency');
        $post(['basis' => 'Quote from the agency vs hiring internally'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $post(['basis' => 'Quote from the agency vs hiring internally', 'month' => '2026-12', 'amount' => 5000, 'currency' => 'INR'])->assertStatus(422)->assertJsonValidationErrors('month');
        $uuid = $post(['basis' => 'Quote from the agency vs hiring internally', 'amount' => 5000, 'currency' => 'inr', 'hours' => 10])->assertCreated()->assertJsonPath('confidence', 'claimed')->json('uuid');
        $r = $this->report()->assertOk();
        $this->assertEquals(['INR' => 5000.0], $r->json('totals.claimed_amount'));
        $this->assertEquals(10.0, $r->json('totals.claimed_hours'));
        $this->assertSame([], $r->json('totals.verified_recovered')); // a claim never becomes verified
        $this->deleteJson(self::V."entries/$uuid")->assertNoContent();
        $this->assertSame([], $this->report()->json('claims'));
    }

    public function test_the_ledger_is_company_wide_only(): void
    {
        $this->signIn($this->userOf($this->mgr));
        $this->report()->assertForbidden();
        // A role with the permission but only a personal data scope is still refused: the ledger spans the company.
        $role = DB::table('roles')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'name' => 'Scoped viewer', 'description' => 'x', 'data_scope' => 'self', 'system' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('key', 'intelligence.value.view')->value('id')]);
        $viewer = $this->userOf($this->emp1);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $viewer->id]);
        $this->signIn($viewer);
        $this->report()->assertForbidden();
    }

    public function test_range_rules_and_tenant_isolation(): void
    {
        $this->recoveredCase('2026-09-10 10:00:00', 3000);
        $this->report('2024-01', '2026-09')->assertStatus(422)->assertJsonValidationErrors('to');
        $this->report('2026-09', '2026-08')->assertStatus(422);
        $this->report('2026-09', '2026-12')->assertStatus(422)->assertJsonValidationErrors('month');
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->assertSame([], $this->report()->assertOk()->json('totals.verified_recovered'));
    }
}
