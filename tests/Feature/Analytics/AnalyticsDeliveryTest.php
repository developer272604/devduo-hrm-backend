<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\ReportDelivery;
use App\Models\InAppNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Performance\PerformanceTestCase;

/** Scheduled delivery, role dashboards, async exports and tenant isolation for the Phase 13 continuation. */
class AnalyticsDeliveryTest extends PerformanceTestCase
{
    private const A = '/api/v1/analytics/';

    private function spec(array $extra = []): array
    {
        return $extra + ['metric' => 'headcount', 'from' => '2026-01-01', 'to' => '2026-10-07', 'dimension' => 'total'];
    }

    private function report(): string
    {
        return $this->postJson(self::A.'reports', ['name' => 'Weekly headcount', 'spec' => $this->spec()])->assertCreated()->json('uuid');
    }

    private function schedule(array $extra = []): array
    {
        return $this->postJson(self::A.'schedules', $extra + ['report_uuid' => $this->report(), 'frequency' => 'daily', 'time_of_day' => '08:00', 'recipient_ids' => [$this->admin->id, $this->checker->id]])->assertCreated()->json();
    }

    private function runDelivery(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->assertSame(0, Artisan::call('analytics:deliver-reports'));
        $this->context($this->admin->tenant_id);
    }

    private function revokeUserPermission(int $userId, string $permission): void
    {
        $user = \App\Models\User::findOrFail($userId);
        $roleIds = DB::table('role_user')->where('user_id', $userId)->pluck('role_id');
        $permissionId = DB::table('permissions')->where('key', $permission)->value('id');
        $newRole = DB::table('roles')->insertGetId(['tenant_id' => $user->tenant_id, 'name' => 'Restricted '.uniqid(), 'description' => 'Test-only restricted role', 'system' => false, 'data_scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('permission_role')->whereIn('role_id', $roleIds)->where('permission_id', '!=', $permissionId)->distinct()->pluck('permission_id') as $id) {
            DB::table('permission_role')->insert(['role_id' => $newRole, 'permission_id' => $id]);
        }
        DB::table('role_user')->where('user_id', $userId)->delete();
        DB::table('role_user')->insert(['role_id' => $newRole, 'user_id' => $userId]);
    }

    public function test_next_run_uses_tenant_timezone_and_is_dst_safe(): void
    {
        $after = CarbonImmutable::parse('2026-10-07 04:30:00', 'UTC');
        $this->assertSame('2026-10-08 02:30:00', ReportDelivery::nextRun('daily', null, null, '08:00', $after, 'Asia/Kolkata')->format('Y-m-d H:i:s'));
        // Weekly Monday 09:00 New York: before DST ends (EDT, UTC-4) and after (EST, UTC-5).
        $this->assertSame('2026-10-26 13:00:00', ReportDelivery::nextRun('weekly', 1, null, '09:00', CarbonImmutable::parse('2026-10-20 00:00:00', 'UTC'), 'America/New_York')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 14:00:00', ReportDelivery::nextRun('weekly', 1, null, '09:00', CarbonImmutable::parse('2026-10-27 00:00:00', 'UTC'), 'America/New_York')->format('Y-m-d H:i:s'));
        // Monthly on the 31st clamps to the last day of short months.
        $this->assertSame('2027-02-28 03:30:00', ReportDelivery::nextRun('monthly', null, 31, '09:00', CarbonImmutable::parse('2027-02-01 00:00:00', 'UTC'), 'Asia/Kolkata')->format('Y-m-d H:i:s'));
    }

    public function test_delivery_is_idempotent_records_attempts_and_notifies_in_app(): void
    {
        $s = $this->schedule(['channels' => ['in_app', 'email']]);
        $this->assertSame('2026-10-08T02:30:00+00:00', CarbonImmutable::parse($s['next_run_at'])->utc()->toIso8601String());
        $this->runDelivery('2026-10-08 03:00:00');
        $this->assertSame(2, DB::table('analytics_deliveries')->where('channel', 'in_app')->where('status', 'sent')->count());
        $this->assertSame(2, InAppNotification::where('category', 'analytics.report')->count());
        $this->assertSame(2, DB::table('analytics_deliveries')->where('channel', 'email')->where('status', 'unavailable')->count());
        // Re-run without a new slot: nothing happens. Replay the same slot (crash recovery): still no duplicates.
        $this->runDelivery('2026-10-08 03:05:00');
        DB::table('analytics_schedules')->update(['next_run_at' => '2026-10-08 02:30:00']);
        $this->runDelivery('2026-10-08 03:10:00');
        $this->assertSame(4, DB::table('analytics_deliveries')->count());
        $this->assertSame(2, InAppNotification::where('category', 'analytics.report')->count());
        $this->assertSame(1, DB::table('analytics_deliveries')->where('recipient_id', $this->admin->id)->where('channel', 'in_app')->value('attempts'));
        $this->assertSame('2026-10-09T02:30:00+00:00', CarbonImmutable::parse(DB::table('analytics_schedules')->value('next_run_at'), 'UTC')->toIso8601String());
    }

    public function test_recipients_are_rechecked_at_send_time_and_get_only_their_own_scope(): void
    {
        $manager = $this->userOf($this->mgr);
        $s = $this->schedule(['recipient_ids' => [$this->admin->id, $manager->id, $this->checker->id]]);
        // Creation refuses a recipient without the permission.
        $this->postJson(self::A.'schedules', ['report_uuid' => $s['report']['uuid'], 'frequency' => 'daily', 'time_of_day' => '08:00', 'recipient_ids' => [$this->userOf($this->emp1)->id]])->assertUnprocessable();
        // The checker loses analytics access before the send.
        $this->revokeUserPermission($this->checker->id, 'analytics.view');
        $this->runDelivery('2026-10-08 03:00:00');
        $status = DB::table('analytics_deliveries')->pluck('status', 'recipient_id');
        $this->assertSame('sent', $status[$this->admin->id]);
        $this->assertSame('sent', $status[$manager->id]);
        $this->assertSame('skipped_permission', $status[$this->checker->id]);
        $this->assertNull(DB::table('analytics_deliveries')->where('recipient_id', $this->checker->id)->value('path'));
        // The manager's file reflects the manager's own (small) scope: suppressed, never the tenant total.
        $path = DB::table('analytics_deliveries')->where('recipient_id', $manager->id)->value('path');
        $csv = Storage::disk('local')->get($path);
        $this->assertStringContainsString(',yes,', $csv);
        $this->assertStringNotContainsString('Total,5,', $csv);
        $this->assertStringContainsString('Total,5,', Storage::disk('local')->get(DB::table('analytics_deliveries')->where('recipient_id', $this->admin->id)->value('path')));
    }

    public function test_owner_losing_export_permission_stops_delivery(): void
    {
        $this->schedule(['recipient_ids' => [$this->admin->id]]);
        $this->revokeUserPermission($this->admin->id, 'analytics.dashboards.manage');
        $this->runDelivery('2026-10-08 03:00:00');
        $this->assertSame('skipped_permission', DB::table('analytics_deliveries')->value('status'));
        $this->assertSame(0, InAppNotification::where('category', 'analytics.report')->count());
    }

    public function test_signed_expiring_download_is_recipient_only_and_rechecks_permission(): void
    {
        $this->schedule(['recipient_ids' => [$this->admin->id]]);
        $this->runDelivery('2026-10-08 03:00:00');
        $this->signIn($this->admin);
        $delivery = $this->getJson(self::A.'deliveries')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $link = $this->getJson(self::A.'deliveries/'.$delivery['uuid'].'/link')->assertOk()->json('url');
        $this->get($link)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->get(self::A.'deliveries/'.$delivery['uuid'].'/download')->assertForbidden(); // unsigned
        $this->actAs($this->mgr);
        $this->getJson(self::A.'deliveries/'.$delivery['uuid'].'/link')->assertNotFound();
        $this->signIn($this->admin);
        $this->travel(11)->minutes();
        $this->get($link)->assertForbidden(); // link expired
        $this->travel(8)->days();
        $this->getJson(self::A.'deliveries/'.$delivery['uuid'].'/link')->assertStatus(410);
        Artisan::call('analytics:deliver-reports');
        $this->context($this->admin->tenant_id);
        $this->assertNull(DB::table('analytics_deliveries')->where('uuid', $delivery['uuid'])->value('path')); // file purged after expiry
    }

    public function test_analytics_legal_hold_blocks_expired_delivery_and_export_file_purge(): void
    {
        $this->schedule(['recipient_ids' => [$this->admin->id]]);
        $this->runDelivery('2026-10-08 03:00:00');
        $delivery = DB::table('analytics_deliveries')->where('channel', 'in_app')->first();
        $job = $this->postJson(self::A.'exports', $this->spec())->assertStatus(202)->json();
        $deliveryPath = $delivery->path;
        $exportPath = DB::table('analytics_export_jobs')->where('uuid', $job['uuid'])->value('path');
        DB::table('legal_holds')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'name' => 'Analytics evidence hold',
            'reason' => 'Preserve analytics reports for review.', 'module' => 'analytics', 'subject_ref' => null, 'placed_by' => $this->admin->id, 'placed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->travel(8)->days();
        Artisan::call('analytics:deliver-reports');
        $this->context($this->admin->tenant_id);
        $this->assertSame($deliveryPath, DB::table('analytics_deliveries')->where('uuid', $delivery->uuid)->value('path'));
        $this->assertSame('completed', DB::table('analytics_export_jobs')->where('uuid', $job['uuid'])->value('status'));
        $this->assertSame($exportPath, DB::table('analytics_export_jobs')->where('uuid', $job['uuid'])->value('path'));

        DB::table('legal_holds')->where('module', 'analytics')->update(['released_at' => now(), 'released_by' => $this->admin->id, 'release_reason' => 'Review complete.']);
        Artisan::call('analytics:deliver-reports');
        $this->context($this->admin->tenant_id);
        $this->assertNull(DB::table('analytics_deliveries')->where('uuid', $delivery->uuid)->value('path'));
        $this->assertSame('expired', DB::table('analytics_export_jobs')->where('uuid', $job['uuid'])->value('status'));
        $this->assertNull(DB::table('analytics_export_jobs')->where('uuid', $job['uuid'])->value('path'));
    }

    public function test_schedules_are_owner_scoped_and_need_the_manage_permission(): void
    {
        $s = $this->schedule();
        $this->actAs($this->mgr);
        $this->getJson(self::A.'schedules')->assertForbidden();
        $this->signIn($this->checker);
        $this->getJson(self::A.'schedules')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson(self::A.'schedules/'.$s['uuid'])->assertNotFound();
        $this->signIn($this->admin);
        $this->putJson(self::A.'schedules/'.$s['uuid'], ['report_uuid' => $s['report']['uuid'], 'frequency' => 'weekly', 'day_of_week' => 1, 'time_of_day' => '09:30', 'recipient_ids' => [$this->admin->id]])->assertOk()->assertJsonPath('frequency', 'weekly');
        $this->postJson(self::A.'schedules', ['report_uuid' => $s['report']['uuid'], 'frequency' => 'weekly', 'time_of_day' => '25:00'])->assertUnprocessable();
    }

    public function test_dashboards_are_role_specific_scoped_and_drill_to_permissioned_lists(): void
    {
        $widgets = fn (array $w) => ['name' => 'Board', 'widgets' => $w];
        $this->postJson(self::A.'dashboards', $widgets([['title' => 'OT', 'metric' => 'overtime']]) + ['audience' => 'employee'])->assertUnprocessable();
        $this->postJson(self::A.'dashboards', $widgets([['title' => 'Pay', 'metric' => 'payroll_totals']]) + ['audience' => 'manager'])->assertUnprocessable();
        $this->postJson(self::A.'dashboards', $widgets([['title' => 'Team', 'metric' => 'headcount', 'dimension' => 'department']]) + ['audience' => 'employee'])->assertUnprocessable();
        $safe = $this->postJson(self::A.'dashboards', ['name' => 'Everyone', 'audience' => 'employee', 'widgets' => [['title' => 'Headcount', 'metric' => 'headcount']]])->assertCreated()->json();
        $hr = $this->postJson(self::A.'dashboards', ['name' => 'HR', 'audience' => 'hr', 'widgets' => [['title' => 'Headcount by dept', 'metric' => 'headcount', 'dimension' => 'department'], ['title' => 'Overtime', 'metric' => 'overtime']]])->assertCreated()->json();
        $exec = $this->postJson(self::A.'dashboards', ['name' => 'Exec', 'audience' => 'executive', 'widgets' => [['title' => 'Cost', 'metric' => 'payroll_cost_trend']]])->assertCreated()->json();

        $board = $this->getJson(self::A.'dashboards/'.$hr['uuid'].'?as_of=2026-10-06')->assertOk()->json();
        $this->assertSame('2026-10-06', $board['as_of']);
        $this->assertSame('ok', $board['widgets'][0]['status']);
        $this->assertSame('/v1/employees', $board['widgets'][0]['drilldown']['api']);
        $this->assertSame('department_id', $board['widgets'][0]['drilldown']['row_filter']);
        $this->assertSame('Engineering', $board['widgets'][0]['result']['rows'][0]['group']);
        $this->assertNotEmpty($board['widgets'][0]['result']['rows'][0]['key']);
        $this->getJson(self::A.'dashboards/'.$hr['uuid'].'?as_of=2027-01-01')->assertUnprocessable();

        // A manager sees only boards at or below their reach and computes widgets in their own scope.
        $this->actAs($this->mgr);
        $uuids = collect($this->getJson(self::A.'dashboards')->assertOk()->json('data'))->pluck('uuid');
        $this->assertTrue($uuids->contains($safe['uuid']));
        $this->assertFalse($uuids->contains($hr['uuid']));
        $this->assertFalse($uuids->contains($exec['uuid']));
        $this->getJson(self::A.'dashboards/'.$hr['uuid'])->assertNotFound();
        $mine = $this->getJson(self::A.'dashboards/'.$safe['uuid'])->assertOk()->json('widgets.0');
        $this->assertTrue($mine['result']['rows'][0]['suppressed']);
        $this->assertNull($mine['result']['rows'][0]['value']);
        $this->postJson(self::A.'dashboards', ['name' => 'X', 'audience' => 'manager', 'widgets' => [['title' => 'H', 'metric' => 'headcount']]])->assertForbidden();

        // A viewer who loses a source permission gets a restricted widget, not the data.
        $this->signIn($this->admin);
        $permission = DB::table('permissions')->where('key', 'attendance.reports.view')->value('id');
        DB::table('permission_role')->whereIn('role_id', $this->admin->roles()->pluck('roles.id'))->where('permission_id', $permission)->delete();
        $board = $this->getJson(self::A.'dashboards/'.$hr['uuid'])->assertOk()->json();
        $this->assertSame('ok', $board['widgets'][0]['status']);
        $this->assertSame('restricted', $board['widgets'][1]['status']);
        $this->assertArrayNotHasKey('result', $board['widgets'][1]);
    }

    public function test_async_export_is_row_limited_audited_owner_only_and_expires(): void
    {
        $job = $this->postJson(self::A.'exports', $this->spec(['with_counts' => true]))->assertStatus(202)->json();
        $this->assertSame('completed', $job['status']);
        $this->assertSame([], $job['masked_columns']);
        $csv = $this->get(self::A.'exports/'.$job['uuid'].'/download')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
        $this->assertStringContainsString('Numerator,Denominator', $csv);
        $this->assertStringContainsString('Total,5,employees,no', $csv);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'analytics.export.completed')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'analytics.export.downloaded')->exists());
        $this->signIn($this->checker);
        $this->getJson(self::A.'exports/'.$job['uuid'])->assertNotFound();
        $this->get(self::A.'exports/'.$job['uuid'].'/download')->assertNotFound();
        $this->actAs($this->mgr);
        $this->postJson(self::A.'exports', $this->spec())->assertForbidden();
        $this->signIn($this->admin);
        $this->travel(3)->days();
        $this->get(self::A.'exports/'.$job['uuid'].'/download')->assertStatus(410);
    }

    public function test_dashboards_schedules_exports_and_deliveries_do_not_cross_tenants(): void
    {
        $s = $this->schedule(['recipient_ids' => [$this->admin->id]]);
        $board = $this->postJson(self::A.'dashboards', ['name' => 'HR', 'audience' => 'hr', 'widgets' => [['title' => 'H', 'metric' => 'headcount']]])->assertCreated()->json();
        $job = $this->postJson(self::A.'exports', $this->spec())->assertStatus(202)->json();
        $this->runDelivery('2026-10-08 03:00:00');
        $delivery = DB::table('analytics_deliveries')->value('uuid');
        $alpha = $this->admin->tenant_id;

        $this->bootTenant('beta');
        $this->signIn($this->admin);
        $this->assertNotSame($alpha, $this->admin->tenant_id);
        $this->getJson(self::A.'dashboards')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::A.'dashboards/'.$board['uuid'])->assertNotFound();
        $this->getJson(self::A.'schedules')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson(self::A.'schedules/'.$s['uuid'])->assertNotFound();
        $this->getJson(self::A.'exports/'.$job['uuid'])->assertNotFound();
        $this->getJson(self::A.'deliveries/'.$delivery.'/link')->assertNotFound();
        $this->postJson(self::A.'schedules', ['report_uuid' => $s['report']['uuid'], 'frequency' => 'daily', 'time_of_day' => '08:00'])->assertNotFound();
        // Running the command for beta never touches alpha's schedule.
        Artisan::call('analytics:deliver-reports', ['--tenant' => $this->admin->tenant_id]);
        $this->context($alpha);
        $this->assertSame(1, DB::table('analytics_deliveries')->where('tenant_id', $alpha)->count());
    }
}
