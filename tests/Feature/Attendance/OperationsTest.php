<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Insights\ActionCenter;
use App\Domain\Quality\DataQualityEngine;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->tenantId = $this->admin->tenant_id;
    }

    private function day(int $employeeId, string $date, array $o = []): int
    {
        return DB::table('attendance_days')->insertGetId($o + ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->tenantId, 'employee_id' => $employeeId, 'attendance_date' => $date, 'status' => 'present',
            'confidence' => 'confirmed', 'finalization' => 'open', 'engine_version' => '1', 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function exception(int $employeeId, string $date, array $o = []): void
    {
        DB::table('attendance_exceptions')->insert($o + ['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->tenantId, 'employee_id' => $employeeId, 'attendance_date' => $date, 'type' => 'missing_out',
            'severity' => 'action_required', 'status' => 'open', 'source_rule' => 't', 'fingerprint' => uniqid(), 'detected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_settings_validate_merge_and_audit(): void
    {
        $this->signIn($this->admin);
        $this->putJson('/api/v1/attendance/settings/location', ['mode' => 'banana'])->assertStatus(422);
        $this->putJson('/api/v1/attendance/settings/regularization', ['bogus' => 1])->assertStatus(422);
        $this->putJson('/api/v1/attendance/settings/regularization', ['window_days' => 14])->assertOk()->assertJsonPath('settings.window_days', 14);
        $this->putJson('/api/v1/attendance/settings/status_labels', ['labels' => ['present' => 'In office']])->assertOk();
        $this->putJson('/api/v1/attendance/settings/status_labels', ['labels' => ['nonsense' => 'x']])->assertStatus(422);
        $this->getJson('/api/v1/attendance/settings/status_labels')->assertJsonPath('settings.labels.present', 'In office');
        $this->getJson('/api/v1/attendance/settings/regularization')->assertJsonPath('settings.window_days', 14)->assertJsonPath('settings.max_per_month', null);
        $this->context($this->tenantId);
        $this->assertTrue(AuditLog::where('resource', 'settings:regularization')->exists());
        $this->putJson('/api/v1/attendance/settings/punch', ['server_time_only' => false])->assertStatus(422);
        $this->getJson('/api/v1/attendance/settings/nope')->assertNotFound();
        $this->context($this->tenantId);
        $this->hire('Mia Manager', ['role' => 'Manager']);
        $this->signIn(\App\Models\User::where('email', 'mia@alpha.test')->first());
        $this->getJson('/api/v1/attendance/settings/punch')->assertForbidden();
    }

    public function test_readiness_is_derived_only_from_explicit_conditions(): void
    {
        $a = $this->hire('Asha Rao');
        $this->day($a->id, '2026-10-06', ['finalization' => 'locked']);
        $this->signIn($this->admin);
        $res = $this->getJson('/api/v1/attendance/payroll-readiness?from=2026-10-06&to=2026-10-06')->assertOk()->json();
        $keys = collect($res['conditions'])->keyBy('key');
        $this->assertSame(1, $keys['missing_policies']['count']); // no policy -> not ready
        $this->assertSame('NOT READY', $res['status']);
        $this->exception($a->id, '2026-10-06');
        $res = $this->getJson('/api/v1/attendance/payroll-readiness?from=2026-10-06&to=2026-10-06')->json();
        $this->assertSame(1, collect($res['conditions'])->keyBy('key')['exceptions_remaining']['count']);
        $this->assertSame('NOT READY', $res['status']);
        $this->assertNotEmpty($res['unresolved_employees']);
        $this->assertArrayHasKey('action', $res['conditions'][0]);
    }

    public function test_payroll_output_uses_locked_data_by_default_and_leave_flows_through(): void
    {
        $a = $this->hire('Asha Rao');
        $this->day($a->id, '2026-10-05', ['finalization' => 'locked', 'approved_overtime_minutes' => 45, 'late_minutes' => 5]);
        $this->day($a->id, '2026-10-06', ['finalization' => 'open', 'status' => 'absent', 'confidence' => 'review_required']);
        $this->app->bind(LeaveDataProvider::class, fn () => new class implements LeaveDataProvider
        {
            public function coverage(int $employeeId, string $date): ?array
            {
                return null;
            }

            public function coverageForRange(array $employeeIds, string $from, string $to): array
            {
                return ['*' => null] + collect($employeeIds)->mapWithKeys(fn ($id) => [$id.'|2026-10-06' => ['minutes' => 480, 'portion' => 'full_day', 'reference' => 'L1', 'paid' => true]])->all();
            }
        });
        $this->signIn($this->admin);
        $res = $this->getJson('/api/v1/attendance/payroll-output?from=2026-10-05&to=2026-10-06')->assertOk()->json();
        $row = $res['data'][0];
        $this->assertSame('FINALIZED DATA ONLY', $res['data_status']);
        $this->assertSame(1, $row['present_days']);
        $this->assertSame(45, $row['approved_overtime_minutes']);
        $this->assertSame(1, $row['excluded_open_days']);
        $this->assertSame(0.0, (float) $row['approved_leave']['days']);
        $open = $this->getJson('/api/v1/attendance/payroll-output?from=2026-10-05&to=2026-10-06&include_open=1')->json();
        $this->assertSame('INCLUDES OPEN DATA - not final', $open['data_status']);
        $this->assertSame(1.0, (float) $open['data'][0]['approved_leave']['paid_days']);
        $this->assertTrue($open['data'][0]['includes_open_days']);
        $this->assertSame('REVIEW REQUIRED', $open['data'][0]['confidence']['label']);
    }

    public function test_command_center_scope_and_sections(): void
    {
        $boss = $this->hire('Mia Manager', ['role' => 'Manager']);
        $mine = $this->hire('Tom Report', ['manager' => $boss]);
        $other = $this->hire('Zed Other');
        $this->day($mine->id, '2026-10-07', ['missing_punch' => true, 'status' => 'missing_punch']);
        $this->day($other->id, '2026-10-07', ['missing_punch' => true, 'status' => 'missing_punch']);
        $this->signIn($this->admin);
        $res = $this->getJson('/api/v1/attendance/command-center')->assertOk()->json();
        $this->assertSame(2, $res['sections']['missing_punches']['count']);
        foreach ($res['sections'] as $section) {
            $this->assertArrayHasKey('action', $section);
        }
        $this->signIn(\App\Models\User::where('email', 'mia@alpha.test')->first());
        $this->getJson('/api/v1/attendance/command-center')->assertOk()->assertJsonPath('sections.missing_punches.count', 1);
        $this->getJson('/api/v1/attendance/team/today')->assertOk()->assertJsonPath('groups.missing_punches.count', 1);
    }

    public function test_reports_scope_export_permission_and_audit(): void
    {
        Storage::fake('hrm_exports');
        $a = $this->hire('Asha Rao');
        $this->day($a->id, '2026-10-06', ['late_minutes' => 12]);
        $this->signIn($this->admin);
        $this->getJson('/api/v1/attendance/reports/late_arrival?from=2026-10-01&to=2026-10-31&sort=hack')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/attendance/reports/nope')->assertNotFound();
        $res = $this->postJson('/api/v1/attendance/reports/late_arrival/exports', ['format' => 'csv', 'from' => '2026-10-01'])->assertStatus(202)->json();
        $this->context($this->tenantId);
        $this->assertTrue(AuditLog::where('action', 'exported')->exists());
        $row = DB::table('attendance_exports')->where('uuid', $res['uuid'])->first();
        $this->assertSame('completed', $row->status);
        $this->getJson('/api/v1/attendance/exports/'.$res['uuid'].'/download')->assertOk();
        $this->context($this->tenantId);
        $user = $this->userWithRole('Team member', 'tm@alpha.test');
        $this->signIn($user);
        $this->postJson('/api/v1/attendance/reports/late_arrival/exports', ['format' => 'csv'])->assertForbidden();
        $this->getJson('/api/v1/attendance/reports/daily')->assertForbidden();
    }

    public function test_tenant_isolation_for_reports(): void
    {
        $a = $this->hire('Asha Rao');
        $this->day($a->id, '2026-10-06');
        $other = $this->bootSecond();
        $this->signIn($other);
        $this->getJson('/api/v1/attendance/reports/daily')->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function bootSecond(): \App\Models\User
    {
        $u = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->context($u->tenant_id);

        return $u;
    }

    public function test_registries_integration_action_center_and_quality_rules(): void
    {
        $a = $this->hire('Asha Rao');
        $this->day($a->id, '2026-10-06', ['missing_punch' => true]);
        $this->signIn($this->admin);
        $keys = collect(app(ActionCenter::class)->for($this->admin, 'attendance'))->pluck('key')->all();
        $this->assertContains('attendance.missing_punches', $keys);
        $this->assertContains('attendance.no_shift_tomorrow', $keys);
        $rules = array_keys(app(DataQualityEngine::class)->rules());
        $this->assertContains('attendance_no_policy', $rules);
        $summary = app(DataQualityEngine::class)->scan($this->tenantId, ['attendance_no_policy', 'attendance_no_shift', 'attendance_holiday_calendar_missing']);
        $this->assertSame([], $summary['errors']);
        $this->assertTrue(DB::table('data_quality_findings')->where('rule', 'attendance_no_policy')->exists());
    }

    public function test_cutoff_passed_is_the_only_critical_insight(): void
    {
        $a = $this->hire('Asha Rao');
        $this->day($a->id, '2026-09-10', ['finalization' => 'open']);
        $this->putJson('/api/v1/attendance/settings/cutoffs', [])->assertStatus(401);
        $this->signIn($this->admin);
        $this->putJson('/api/v1/attendance/settings/cutoffs', ['payroll_cutoff_day' => 5])->assertOk();
        $this->context($this->tenantId);
        $by = collect(app(\App\Domain\Attendance\Operations\AttendanceInsightProvider::class)->insights($this->admin))->keyBy('key');
        $this->assertSame('critical', $by['attendance.payroll_cutoff_risk']['severity']);
        $this->assertSame(['critical'], collect($by)->pluck('severity')->filter(fn ($s) => $s === 'critical')->unique()->values()->all());
    }

    public function test_saved_views_search_health_and_dashboard(): void
    {
        $this->hire('Asha Rao');
        $this->signIn($this->admin);
        $this->postJson('/api/v1/attendance/views', ['name' => 'Chennai Missing', 'report' => 'missing_punch', 'filters' => ['location_id' => 1, 'evil' => 'x']])->assertCreated()->assertJsonPath('filters.location_id', 1)->assertJsonMissingPath('filters.evil');
        $this->getJson('/api/v1/attendance/views')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/attendance/search?q=Asha')->assertOk()->assertJsonPath('results.employees.0.name', 'Asha Rao');
        $this->getJson('/api/v1/attendance/health')->assertOk()->assertJsonStructure(['status', 'checks' => ['processing_failures', 'device_sync', 'import_failures', 'queue_failures', 'abnormal_durations']]);
        $this->getJson('/api/v1/attendance/dashboard')->assertOk()->assertJsonPath('team.people_away.source', 'leave_provider');
        $this->getJson('/api/v1/attendance/me/summary')->assertOk();
    }
}
