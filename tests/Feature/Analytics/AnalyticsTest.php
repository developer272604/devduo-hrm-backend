<?php

namespace Tests\Feature\Analytics;

use App\Domain\People\Models\Assignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

class AnalyticsTest extends PerformanceTestCase
{
    public function test_learning_completion_uses_tenant_day_boundaries_and_source_permission(): void
    {
        $tenant = $this->admin->tenant; $settings = $tenant->settings; $settings['timezone'] = 'America/New_York'; $tenant->update(['settings' => $settings]);
        foreach ([$this->mgr, $this->emp1, $this->emp2, $this->emp3, $this->outsider] as $employee) {
            DB::table('learning_enrolments')->insert(['tenant_id' => $tenant->id, 'uuid' => (string) \Illuminate\Support\Str::uuid(), 'course_id' => 999,
                'employee_id' => $employee->id, 'status' => 'completed', 'completed_at' => '2026-10-07 08:30:00', 'created_at' => now(), 'updated_at' => now()]);
        }
        // 08:30 India is 23:00 New York on the preceding day in October.
        $this->signIn($this->admin);
        $this->postJson('/api/v1/analytics/query', $this->spec(['metric' => 'learning_completions', 'from' => '2026-10-06', 'to' => '2026-10-06']))->assertOk()->assertJsonPath('rows.0.value', 5);
        $this->postJson('/api/v1/analytics/query', $this->spec(['metric' => 'learning_completions', 'from' => '2026-10-05', 'to' => '2026-10-05']))->assertOk()->assertJsonPath('rows.0.value', null);
    }
    private function spec(array $extra = []): array
    {
        return $extra + ['metric' => 'headcount', 'from' => '2026-01-01', 'to' => '2026-10-07', 'dimension' => 'total'];
    }

    public function test_headcount_reconciles_with_historical_status_and_not_current_status(): void
    {
        $this->postJson('/api/v1/analytics/query', $this->spec())->assertOk()->assertJsonPath('rows.0.value', 5)->assertJsonPath('rows.0.numerator', 5);
        // A later status transition must not rewrite an earlier snapshot.
        DB::table('employees')->where('id', $this->emp1->id)->update(['status' => 'separated', 'separated_on' => '2026-10-07']);
        DB::table('employee_status_history')->insert(['tenant_id' => $this->admin->tenant_id, 'employee_id' => $this->emp1->id, 'from_status' => 'active', 'to_status' => 'separated', 'effective_date' => '2026-10-07', 'changed_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson('/api/v1/analytics/query', $this->spec(['to' => '2026-10-06']))->assertOk()->assertJsonPath('rows.0.value', 5);
        $this->postJson('/api/v1/analytics/query', $this->spec())->assertOk()->assertJsonPath('rows.0.value', null)->assertJsonPath('rows.0.suppressed', true);
        $this->assertSame(3, DB::table('analytics_refresh_runs')->count());
        $this->assertSame(3, DB::table('analytics_access_logs')->count());
    }

    public function test_manager_scope_and_small_breakdowns_never_reveal_remainders(): void
    {
        $this->actAs($this->mgr);
        $this->postJson('/api/v1/analytics/query', $this->spec())->assertOk()->assertJsonPath('rows.0.value', null);
        $this->actAs($this->emp1);
        $this->postJson('/api/v1/analytics/query', $this->spec())->assertForbidden();
        $this->signIn($this->admin);
        DB::table('employee_assignments')->where('employee_id', $this->emp1->id)->update(['department_id' => $this->org['department'][1]]);
        $this->postJson('/api/v1/analytics/query', $this->spec(['dimension' => 'department']))->assertOk()->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.group', 'Breakdown withheld')->assertJsonPath('rows.0.numerator', null);
    }

    public function test_effective_dated_assignment_and_data_quality_guard(): void
    {
        $this->signIn($this->admin);
        $old = Assignment::where('employee_id', $this->emp1->id)->first();
        $copy = $old->replicate();
        $old->update(['effective_to' => '2026-09-30']);
        $copy->effective_from = '2026-10-01';
        $copy->effective_to = null;
        $copy->department_id = $this->org['department'][1];
        $copy->save();
        $this->postJson('/api/v1/analytics/query', $this->spec(['to' => '2026-09-30', 'dimension' => 'department']))->assertOk()->assertJsonPath('rows.0.group', 'Engineering')->assertJsonPath('rows.0.value', 5);
        $this->postJson('/api/v1/analytics/query', $this->spec(['dimension' => 'department']))->assertOk()->assertJsonPath('rows.0.suppressed', true);
        $this->signIn($this->admin);
        $duplicate = $copy->replicate();
        $duplicate->effective_from = '2026-10-02';
        $duplicate->save();
        $this->postJson('/api/v1/analytics/query', $this->spec(['dimension' => 'department']))->assertOk()->assertJsonPath('quality.overlapping_assignments', true)->assertJsonCount(0, 'rows');
    }

    public function test_saved_reports_recheck_permissions_and_are_private_and_tenant_scoped(): void
    {
        $r = $this->postJson('/api/v1/analytics/reports', ['name' => 'Workforce', 'spec' => $this->spec()])->assertCreated()->json();
        $this->signIn($this->checker);
        $this->postJson('/api/v1/analytics/reports/'.$r['uuid'].'/run')->assertNotFound();
        $this->signIn($this->admin);
        $this->postJson('/api/v1/analytics/reports/'.$r['uuid'].'/run')->assertOk();
        $this->signIn($this->admin);
        $permission = DB::table('permissions')->where('key', 'people.view')->value('id');
        $roles = $this->admin->roles()->pluck('roles.id');
        DB::table('permission_role')->whereIn('role_id', $roles)->where('permission_id', $permission)->delete();
        $this->postJson('/api/v1/analytics/reports/'.$r['uuid'].'/run')->assertForbidden();
        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->postJson('/api/v1/analytics/reports/'.$r['uuid'].'/run')->assertNotFound();
    }

    public function test_attendance_totals_exclude_unfinalized_days_and_reconcile(): void
    {
        foreach ([$this->mgr, $this->emp1, $this->emp2, $this->emp3, $this->outsider] as $employee) {
            DB::table('attendance_days')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'employee_id' => $employee->id, 'attendance_date' => '2026-10-06', 'status' => 'present', 'required_minutes' => 480, 'approved_overtime_minutes' => 30, 'confidence' => 'confirmed', 'finalization' => 'locked', 'engine_version' => 'test', 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->postJson('/api/v1/analytics/query', $this->spec(['metric' => 'overtime']))->assertOk()->assertJsonPath('rows.0.value', 150);
        DB::table('attendance_days')->where('employee_id', $this->emp1->id)->update(['finalization' => 'open']);
        $this->postJson('/api/v1/analytics/query', $this->spec(['metric' => 'overtime']))->assertOk()->assertJsonPath('rows.0.value', null);
    }

    public function test_scenario_is_explicit_non_mutating_and_exports_are_audited(): void
    {
        $before = DB::table('employees')->count();
        $this->postJson('/api/v1/analytics/scenarios', ['from' => '2026-01-01', 'to' => '2026-10-07', 'hires' => 3, 'leavers' => 1])->assertOk()->assertJsonPath('projected', 7);
        $this->assertSame($before, DB::table('employees')->count());
        $this->postJson('/api/v1/analytics/scenarios', ['from' => '2026-01-01', 'to' => '2026-10-07', 'hires' => 0, 'leavers' => 6])->assertUnprocessable();
        $csv = $this->get('/api/v1/analytics/export?'.http_build_query($this->spec()))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
        $this->assertStringContainsString('Total,5,employees,no', $csv);
        $this->assertSame(1, DB::table('analytics_access_logs')->where('action', 'export')->count());
        $this->actAs($this->mgr);
        $this->get('/api/v1/analytics/export?'.http_build_query($this->spec()))->assertForbidden();
    }

    public function test_bounds_and_disabled_source_modules_are_enforced(): void
    {
        $this->postJson('/api/v1/analytics/query', $this->spec(['to' => '2027-01-01']))->assertUnprocessable();
        $this->postJson('/api/v1/analytics/query', $this->spec(['metric' => 'salary']))->assertUnprocessable();
        $tenant = $this->admin->tenant;
        $s = $tenant->settings;
        $s['modules'] = array_values(array_diff($s['modules'], ['people']));
        $tenant->update(['settings' => $s]);
        $this->signIn($this->admin);
        $this->postJson('/api/v1/analytics/query',$this->spec())->assertForbidden();
    }
}
