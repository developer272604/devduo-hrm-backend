<?php

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

/** Phase 13 continuation: new trusted metrics, suppression, diversity gating, org-name history and explainability. */
class AnalyticsMetricsTest extends PerformanceTestCase
{
    private function spec(array $extra = []): array
    {
        return $extra + ['metric' => 'headcount', 'from' => '2026-01-01', 'to' => '2026-10-07', 'dimension' => 'total'];
    }

    private function q(array $extra = [])
    {
        return $this->postJson('/api/v1/analytics/query', $this->spec($extra));
    }

    /** @return array<int,\App\Domain\People\Models\Employee> */
    private function staff(): array
    {
        return [$this->mgr, $this->emp1, $this->emp2, $this->emp3, $this->outsider];
    }

    private function deferFks(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    public function test_retention_of_hire_cohort_and_small_leaver_suppression(): void
    {
        $this->q(['metric' => 'retention_cohort'])->assertOk()->assertJsonPath('rows.0.value', 100)->assertJsonPath('rows.0.numerator', 5)->assertJsonPath('rows.0.denominator', 5);
        DB::table('employees')->where('id', $this->emp1->id)->update(['status' => 'separated', 'separated_on' => '2026-10-01']);
        DB::table('employee_status_history')->insert(['tenant_id' => $this->admin->tenant_id, 'employee_id' => $this->emp1->id, 'from_status' => 'active', 'to_status' => 'separated', 'effective_date' => '2026-10-01', 'changed_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        // One leaver in a cohort of five is identifiable by subtraction: withheld, never shown as 80.
        $this->q(['metric' => 'retention_cohort'])->assertOk()->assertJsonPath('rows.0.value', null)->assertJsonPath('rows.0.suppressed', true);
        $this->q(['metric' => 'retention_cohort', 'to' => '2026-09-30'])->assertOk()->assertJsonPath('rows.0.value', 100);
    }

    public function test_training_compliance_percentage_and_small_noncompliant_suppression(): void
    {
        $tenant = $this->admin->tenant_id;
        $row = fn ($e, $cycle, $status) => ['tenant_id' => $tenant, 'uuid' => (string) Str::uuid(), 'employee_id' => $e->id, 'course_id' => 1, 'cycle_key' => $cycle, 'assigned_on' => '2026-09-01', 'due_on' => '2026-09-30', 'status' => $status, 'created_at' => now(), 'updated_at' => now()];
        foreach ($this->staff() as $e) {
            DB::table('learning_assignments')->insert($row($e, 'a', 'completed'));
        }
        $this->q(['metric' => 'training_compliance'])->assertOk()->assertJsonPath('rows.0.value', 100);
        DB::table('learning_assignments')->insert($row($this->emp1, 'b', 'assigned'));
        $this->q(['metric' => 'training_compliance'])->assertOk()->assertJsonPath('rows.0.suppressed', true)->assertJsonPath('rows.0.value', null);
        foreach ([$this->mgr, $this->emp2, $this->emp3, $this->outsider] as $e) {
            DB::table('learning_assignments')->insert($row($e, 'b', 'assigned'));
        }
        DB::table('learning_assignments')->insert($row($this->mgr, 'w', 'waived')); // waived is excluded
        $this->q(['metric' => 'training_compliance'])->assertOk()->assertJsonPath('rows.0.value', 50)->assertJsonPath('rows.0.numerator', 5)->assertJsonPath('rows.0.denominator', 10);
    }

    public function test_leave_liability_is_decimal_safe_and_ignores_reservations_and_future_entries(): void
    {
        $this->deferFks();
        $tenant = $this->admin->tenant_id;
        $type = DB::table('leave_types')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'name' => 'Annual', 'code' => 'AL', 'category' => 'paid', 'unit' => 'day', 'tracks_balance' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $entry = function ($e, $account, $kind, $units, $date, $n) use ($tenant, $type) {
            DB::table('leave_ledger_entries')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'account_id' => $account, 'employee_id' => $e->id, 'leave_type_id' => $type, 'entry_type' => $kind,
                'bucket' => 'accrued', 'units' => $units, 'effective_date' => $date, 'idempotency_key' => 'k'.$n, 'posted_at' => now()]);
        };
        $n = 0;
        foreach ($this->staff() as $e) {
            $account = DB::table('leave_accounts')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'employee_id' => $e->id, 'leave_type_id' => $type, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);
            $entry($e, $account, 'accrual', '10.2500', '2026-03-01', ++$n);
            $entry($e, $account, 'consumption', '-3.1000', '2026-06-01', ++$n);
            $entry($e, $account, 'reservation', '-2.0000', '2026-09-01', ++$n);
            $entry($e, $account, 'accrual', '100.0000', '2026-12-01', ++$n); // after the as-of date
        }
        $r = $this->q(['metric' => 'leave_liability'])->assertOk()->json('rows.0');
        $this->assertSame('35.7500', $r['value']);
        $this->assertFalse($r['suppressed']);
    }

    public function test_rating_distribution_withholds_whole_distribution_when_any_bucket_is_small(): void
    {
        $this->cycle();
        $tenant = $this->admin->tenant_id;
        $this->context($tenant);
        DB::table('performance_reviews')->update(['status' => 'finalized', 'final_rating' => 'Meets', 'finalized_at' => '2026-10-05 06:00:00']);
        $total = DB::table('performance_reviews')->count();
        $this->assertGreaterThanOrEqual(5, $total);
        $this->q(['metric' => 'rating_distribution'])->assertOk()->assertJsonPath('rows.0.group', 'Meets')->assertJsonPath('rows.0.value', $total);
        DB::table('performance_reviews')->where('employee_id', $this->emp1->id)->update(['final_rating' => 'Exceeds']);
        $rows = $this->q(['metric' => 'rating_distribution'])->assertOk()->json('rows');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertNull($row['value']);
            $this->assertTrue($row['suppressed']);
        }
    }

    public function test_time_to_hire_and_offer_acceptance(): void
    {
        $this->deferFks();
        $tenant = $this->admin->tenant_id;
        for ($i = 1; $i <= 5; $i++) {
            $app = DB::table('recruitment_applications')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'candidate_id' => $i, 'requisition_id' => 1, 'stage' => 'offer', 'status' => 'active', 'source' => 'x',
                'stage_entered_at' => '2026-09-01 06:00:00', 'created_at' => '2026-09-01 06:00:00', 'updated_at' => now()]);
            DB::table('recruitment_offers')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'application_id' => $app, 'version' => 1, 'status' => 'accepted', 'job_title' => 'Engineer', 'start_date' => '2026-10-20',
                'created_by' => $this->admin->id, 'responded_at' => '2026-09-11 06:00:00', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->q(['metric' => 'time_to_hire'])->assertOk()->assertJsonPath('rows.0.value', 10)->assertJsonPath('rows.0.denominator', 5);
        $this->q(['metric' => 'offer_accept_rate'])->assertOk()->assertJsonPath('rows.0.value', 100);
        $app = DB::table('recruitment_applications')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'candidate_id' => 9, 'requisition_id' => 1, 'stage' => 'offer', 'status' => 'active', 'source' => 'x', 'stage_entered_at' => '2026-09-01 06:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('recruitment_offers')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'application_id' => $app, 'version' => 1, 'status' => 'declined', 'job_title' => 'Engineer', 'start_date' => '2026-10-20', 'created_by' => $this->admin->id, 'responded_at' => '2026-09-12 06:00:00', 'created_at' => now(), 'updated_at' => now()]);
        // One decline would be revealed by 100 - rate: withheld.
        $this->q(['metric' => 'offer_accept_rate'])->assertOk()->assertJsonPath('rows.0.value', null)->assertJsonPath('rows.0.suppressed', true);
        $this->actAs($this->mgr);
        $this->q(['metric' => 'time_to_hire'])->assertForbidden();
    }

    public function test_payroll_cost_trend_is_decimal_safe_permission_gated_and_exports_mask_counts(): void
    {
        $this->seedPayroll();
        $rows = $this->q(['metric' => 'payroll_cost_trend'])->assertOk()->json('rows');
        $this->assertCount(2, $rows);
        $this->assertSame(0, bccomp($rows[0]['value']['gross'], '5000.50', 2));
        $this->assertSame(0, bccomp($rows[1]['value']['gross'], '5500.00', 2));
        $this->assertNotNull($rows[1]['value']['gross_change_percent']);
        $by = $this->q(['metric' => 'payroll_cost_trend', 'dimension' => 'department'])->assertOk()->json('rows');
        $this->assertStringStartsWith('Engineering · 2026-08-31', $by[0]['group']);
        $this->actAs($this->mgr);
        $this->q(['metric' => 'payroll_cost_trend'])->assertForbidden();
        $this->signIn($this->admin);
        $job = $this->postJson('/api/v1/analytics/exports', $this->spec(['metric' => 'payroll_cost_trend', 'with_counts' => true]))->assertStatus(202)->json();
        $this->assertSame('completed', $job['status']);
        $this->assertSame(['numerator', 'denominator'], $job['masked_columns']);
        config(['analytics.export_row_limit' => 1]);
        $failed = $this->postJson('/api/v1/analytics/exports', $this->spec(['metric' => 'payroll_cost_trend']))->assertStatus(202)->json();
        $this->assertSame('failed', $failed['status']);
        $this->assertStringContainsString('Row limit', $failed['error']);
    }

    private function seedPayroll(): void
    {
        $this->deferFks();
        $tenant = $this->admin->tenant_id;
        $re = 0;
        foreach ([['2026-08-01', '2026-08-31', '1000.10'], ['2026-09-01', '2026-09-30', '1100.00']] as $i => [$start, $end, $gross]) {
            $period = DB::table('payroll_periods')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'payroll_group_id' => 1, 'label' => $end, 'period_start' => $start, 'period_end' => $end, 'created_at' => now(), 'updated_at' => now()]);
            $run = DB::table('payroll_runs')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'payroll_group_id' => 1, 'period_id' => $period, 'status' => 'locked', 'currency' => 'INR', 'created_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($this->staff() as $e) {
                DB::table('payroll_calculation_results')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $tenant, 'run_id' => $run, 'run_employee_id' => ++$re, 'employee_id' => $e->id, 'version' => 1, 'currency' => 'INR',
                    'gross' => $gross, 'net' => '900.00', 'engine_version' => 't', 'checksum' => 'x'.$i, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function test_diversity_cut_is_disabled_unless_configured_and_small_cells_are_withheld(): void
    {
        $this->q(['dimension' => 'gender'])->assertUnprocessable();
        $this->assertNotContains('gender', collect($this->getJson('/api/v1/analytics/metrics')->json('data'))->firstWhere('key', 'headcount')['dimensions']);
        $tenant = $this->admin->tenant;
        $tenant->update(['settings' => $tenant->settings + ['analytics' => ['diversity_dimensions' => ['gender']]]]);
        $this->signIn($this->admin);
        $this->q(['dimension' => 'gender'])->assertOk()->assertJsonPath('rows.0.group', 'Undisclosed')->assertJsonPath('rows.0.value', 5);
        $this->q(['metric' => 'tenure', 'dimension' => 'gender'])->assertUnprocessable(); // only headcount
        DB::table('people')->whereIn('id', [$this->emp1->person_id, $this->emp2->person_id])->update(['gender' => 'female']);
        $this->q(['dimension' => 'gender'])->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.group', 'Breakdown withheld');
    }

    public function test_historical_org_names_follow_the_as_of_date(): void
    {
        $this->q(['dimension' => 'department'])->assertOk()->assertJsonPath('rows.0.group', 'Engineering');
        DB::table('departments')->where('id', $this->org['department'][0])->update(['name' => 'Platform Engineering']);
        $this->q(['dimension' => 'department', 'to' => '2026-10-06'])->assertOk()->assertJsonPath('rows.0.group', 'Engineering');
        $this->q(['dimension' => 'department'])->assertOk()->assertJsonPath('rows.0.group', 'Platform Engineering');
    }

    public function test_every_number_carries_definition_as_of_filters_and_suppression_note(): void
    {
        $catalog = collect($this->getJson('/api/v1/analytics/metrics')->assertOk()->json('data'));
        foreach (['retention_cohort', 'time_to_hire', 'offer_accept_rate', 'leave_liability', 'payroll_cost_trend', 'training_compliance', 'rating_distribution', 'absence', 'overtime'] as $key) {
            $m = $catalog->firstWhere('key', $key);
            $this->assertNotNull($m, $key);
            foreach (['definition', 'formula', 'source_tables', 'grain', 'suppression_rule'] as $field) {
                $this->assertNotEmpty($m['explain'][$field], "$key $field");
            }
        }
        $r = $this->q()->assertOk()->json();
        $this->assertSame('2026-10-07', $r['explain']['as_of']);
        $this->assertSame('total', $r['explain']['filters']['dimension']);
        $this->assertStringContainsString('No values were withheld', $r['explain']['suppression_note']);
        $this->getJson('/api/v1/analytics/metrics/leave_liability')->assertOk()->assertJsonPath('metric', 'leave_liability');
        $this->actAs($this->mgr);
        $this->getJson('/api/v1/analytics/metrics/payroll_cost_trend')->assertForbidden();
        $this->getJson('/api/v1/analytics/metrics/nope')->assertNotFound();
    }

    public function test_new_metrics_never_leak_across_tenants(): void
    {
        $this->seedPayroll();
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $r = $this->q(['metric' => 'payroll_cost_trend'])->assertOk()->json('rows');
        $this->assertTrue($r[0]['suppressed']);
        $this->assertNull($r[0]['value']);
    }
}
