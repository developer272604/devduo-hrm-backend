<?php

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

class BusinessImpactTest extends PerformanceTestCase
{
    private const I = '/api/v1/analytics/impact/';

    private function staff(): array
    {
        return [$this->mgr, $this->emp1, $this->emp2, $this->emp3, $this->outsider];
    }

    private function seedPayroll(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
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

    private function metric(string $code = 'orders', string $kind = 'output', ?string $currency = null): string
    {
        $this->signIn($this->admin);

        return $this->postJson(self::I.'metrics', ['code' => $code, 'name' => ucfirst($code), 'unit' => $kind === 'revenue' ? 'INR' : 'orders', 'kind' => $kind, 'currency' => $currency])->assertCreated()->json('uuid');
    }

    private function values(string $uuid, array $byMonth)
    {
        return $this->putJson(self::I."metrics/$uuid/values", ['values' => collect($byMonth)->map(fn ($v, $p) => ['period' => $p, 'value' => $v])->values()->all()]);
    }

    private function report(string $from = '2026-08', string $to = '2026-09')
    {
        return $this->getJson(self::I."report?from=$from&to=$to");
    }

    public function test_metric_definitions_are_validated(): void
    {
        $this->metric('orders');
        $this->postJson(self::I.'metrics', ['code' => 'ORDERS', 'name' => 'x', 'unit' => 'u', 'kind' => 'output'])->assertStatus(422)->assertJsonValidationErrors('code'); // codes are case-insensitive unique
        $this->postJson(self::I.'metrics', ['code' => 'sales', 'name' => 'Sales', 'unit' => 'INR', 'kind' => 'revenue'])->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->postJson(self::I.'metrics', ['code' => '1bad code', 'name' => 'x', 'unit' => 'u', 'kind' => 'output'])->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson(self::I.'metrics', ['code' => 'x', 'name' => 'x', 'unit' => 'u', 'kind' => 'other'])->assertStatus(422);
    }

    public function test_cost_per_unit_per_head_and_change_are_computed_from_payroll_and_headcount(): void
    {
        $this->seedPayroll();
        $m = $this->metric('orders');
        $this->values($m, ['2026-08' => 100, '2026-09' => 220])->assertOk()->assertJsonPath('saved', 2);
        $r = $this->report()->assertOk();
        $this->assertTrue($r->json('cost_available'));
        $s = $r->json('metrics.0.series');
        $this->assertEquals(5000.5, $s[0]['cost']['gross']);
        $this->assertEquals(50.005, $s[0]['cost_per_unit']);
        $this->assertEquals(25.0, $s[1]['cost_per_unit']);
        $this->assertEquals(20.0, $s[0]['per_head']); // 100 orders / 5 people
        $this->assertSame('cost_per_unit', $r->json('metrics.0.summary.headline_ratio'));
        $this->assertEquals(-50.0, $r->json('metrics.0.summary.change_percent'));
        $this->assertStringContainsString('do not show that one caused the other', $r->json('limitations'));
    }

    public function test_revenue_is_read_as_payroll_share_and_revenue_per_payroll_unit(): void
    {
        $this->seedPayroll();
        $m = $this->metric('sales', 'revenue', 'inr');
        $this->values($m, ['2026-09' => 110000]);
        $row = $this->report()->assertOk()->json('metrics.0.series.1');
        $this->assertEquals(5.0, $row['payroll_pct_of_revenue']); // 5500 / 110000
        $this->assertEquals(20.0, $row['revenue_per_payroll_unit']);
        $this->assertNull($row['cost_per_unit']);
    }

    public function test_a_revenue_currency_that_differs_from_payroll_is_not_converted(): void
    {
        $this->seedPayroll();
        $m = $this->metric('usd_sales', 'revenue', 'usd');
        $this->values($m, ['2026-09' => 1000]);
        $row = $this->report()->json('metrics.0.series.1');
        $this->assertNull($row['cost']);
        $this->assertNull($row['payroll_pct_of_revenue']);
        $this->assertEquals(1000.0, $row['value']);
    }

    public function test_the_month_in_progress_is_shown_but_never_turned_into_a_ratio(): void
    {
        $this->seedPayroll();
        $m = $this->metric('orders');
        $this->values($m, ['2026-09' => 220, '2026-10' => 50]);
        $s = $this->report('2026-09', '2026-10')->json('metrics.0.series');
        $this->assertTrue($s[1]['partial']);
        $this->assertEquals(50.0, $s[1]['value']);
        $this->assertNull($s[1]['cost_per_unit']);
        $this->assertEquals(25.0, $s[0]['cost_per_unit']);
    }

    public function test_months_without_published_payroll_are_blank_not_zero(): void
    {
        $this->seedPayroll();
        $m = $this->metric('orders');
        $this->values($m, ['2026-07' => 80, '2026-09' => 220]);
        $s = $this->report('2026-07', '2026-09')->json('metrics.0.series');
        $this->assertNull($s[0]['cost_per_unit']); // July: results exist, but no published payroll
        $this->assertNull($s[0]['cost']);
        $this->assertEquals(25.0, $s[2]['cost_per_unit']);
        $this->assertSame(2, $this->report('2026-07', '2026-09')->json('metrics.0.summary.months_with_results'));
    }

    public function test_someone_without_payroll_access_sees_results_but_never_cost(): void
    {
        $this->seedPayroll();
        $m = $this->metric('orders');
        $this->values($m, ['2026-09' => 220]);
        $role = DB::table('roles')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'name' => 'Ops analyst', 'description' => 'x', 'data_scope' => 'tenant', 'system' => false, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['analytics.view', 'analytics.impact.view'] as $key) {
            DB::table('permission_role')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('key', $key)->value('id')]);
        }
        $analyst = $this->userOf($this->emp1);
        DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $analyst->id]);
        $this->signIn($analyst);
        $r = $this->report()->assertOk();
        $this->assertFalse($r->json('cost_available'));
        $this->assertStringContainsString('do not have access to payroll', $r->json('cost_note'));
        $row = $r->json('metrics.0.series.1');
        $this->assertEquals(220.0, $row['value']);
        $this->assertNull($row['cost']);
        $this->assertNull($row['cost_per_unit']);
        $this->postJson(self::I.'metrics', ['code' => 'x', 'name' => 'x', 'unit' => 'u', 'kind' => 'output'])->assertForbidden(); // view only
    }

    public function test_period_rules(): void
    {
        $m = $this->metric('orders');
        $this->values($m, ['2026-11' => 5])->assertStatus(422)->assertJsonValidationErrors('period'); // future month
        $this->values($m, ['1999-12' => 5])->assertStatus(422);
        $this->values($m, ['2026-13' => 5])->assertStatus(422);
        $this->report('2024-01', '2026-09')->assertStatus(422)->assertJsonValidationErrors('to'); // more than 24 months
        $this->report('2026-09', '2026-08')->assertStatus(422);
    }

    public function test_values_can_be_corrected_and_removed(): void
    {
        $m = $this->metric('orders');
        $this->values($m, ['2026-09' => 220]);
        $this->values($m, ['2026-09' => 230])->assertOk();
        $this->assertSame(1, DB::table('impact_values')->count());
        $this->assertEquals(230.0, (float) DB::table('impact_values')->value('value'));
        $this->deleteJson(self::I."metrics/$m/values/2026-09")->assertNoContent();
        $this->assertSame(0, DB::table('impact_values')->count());
    }

    public function test_csv_import_previews_then_commits_only_when_every_row_is_valid(): void
    {
        $this->metric('orders');
        $good = "metric_code,period,value,note\norders,2026-08,100,from ERP\norders,2026-09,220,\n";
        $this->assertSame(0, DB::table('impact_values')->count());
        $p = $this->postJson(self::I.'import', ['csv' => $good])->assertOk();
        $this->assertSame(2, $p->json('rows_valid'));
        $this->assertFalse($p->json('committed'));
        $this->assertSame(0, DB::table('impact_values')->count()); // preview writes nothing

        $bad = $good."ghost,2026-09,5\norders,2026-09,300\norders,2026-9,5\norders,2026-10,abc\n";
        $p = $this->postJson(self::I.'import', ['csv' => $bad])->assertOk();
        $this->assertSame(4, $p->json('rows_with_errors'));
        $this->postJson(self::I.'import', ['csv' => $bad, 'commit' => true])->assertStatus(422);
        $this->assertSame(0, DB::table('impact_values')->count()); // nothing saved when any row is wrong

        $c = $this->postJson(self::I.'import', ['csv' => $good, 'commit' => true])->assertOk();
        $this->assertSame(2, $c->json('rows_written'));
        $this->assertSame('import', DB::table('impact_values')->value('source'));
        $this->postJson(self::I.'import', ['csv' => "metric_code,period\norders,2026-09"])->assertStatus(422)->assertJsonValidationErrors('csv'); // missing value column
    }

    public function test_inactive_metrics_leave_the_report_and_reject_imports(): void
    {
        $m = $this->metric('orders');
        $this->patchJson(self::I."metrics/$m", ['active' => false])->assertOk();
        $this->assertSame([], $this->report()->json('metrics'));
        $this->postJson(self::I.'import', ['csv' => "metric_code,period,value\norders,2026-09,1"])->assertOk()->assertJsonPath('rows_with_errors', 1);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $m = $this->metric('orders');
        $this->signIn($this->userOf($this->emp1));
        $this->getJson(self::I.'metrics')->assertForbidden();
        $this->report()->assertForbidden();

        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson(self::I.'metrics')->assertOk()->assertJsonPath('items', []);
        $this->patchJson(self::I."metrics/$m", ['active' => false])->assertNotFound();
    }
}
