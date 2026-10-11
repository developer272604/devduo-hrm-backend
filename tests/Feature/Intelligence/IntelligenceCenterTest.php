<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Insights\ActionCenter;
use App\Domain\Insights\InsightProvider;
use App\Domain\Intelligence\FindingService;
use App\Domain\Intelligence\IntelligenceEmployee360Provider;
use App\Domain\Intelligence\Models\Finding;
use App\Domain\Intelligence\ScenarioService;
use App\Domain\People\Models\Employee;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

/** Deterministic test provider: rows are keyed by viewer id, so each viewer "sees" only their own scope. */
final class StubInsights implements InsightProvider
{
    public static array $rows = [];

    public function key(): string
    {
        return 'test.stub';
    }

    public function insights(User $viewer): array
    {
        return self::$rows[$viewer->id] ?? [];
    }
}

class IntelligenceCenterTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private const API = '/api/v1/intelligence/';

    private Employee $mgr;

    private Employee $emp;

    private Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        StubInsights::$rows = [];
        $this->bootTenant();
        $this->mgr = $this->hire('Meera Rao', ['role' => 'Manager']);
        $this->emp = $this->hire('Arun Kumar', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->outsider = $this->hire('Omar Diaz', ['role' => 'Team member']);
        // Isolate from the real module providers: only the stub feeds the Center in these tests.
        $this->app->instance(ActionCenter::class, $center = new ActionCenter);
        $center->register(StubInsights::class);
        $this->signIn($this->admin);
    }

    private function userOf(Employee $e): User
    {
        return User::findOrFail(DB::table('people')->where('id', $e->person_id)->value('user_id'));
    }

    private function insight(string $key = 'test.late_docs', int $count = 3, string $severity = 'warning', array $sample = []): array
    {
        return ['key' => $key, 'domain' => 'people', 'severity' => $severity, 'title' => "$count things need attention", 'count' => $count, 'description' => 'Test condition.',
            'actions' => [['label' => 'Open', 'kind' => 'navigate', 'href' => '/people', 'requires_confirmation' => false]], 'sample' => $sample,
            'rule' => 'Stub rule', 'threshold' => 'count >= 1'];
    }

    private function feed(User $u, array $insights): void
    {
        StubInsights::$rows[$u->id] = $insights;
    }

    private function scan(User $u): array
    {
        return $this->signIn($u)->postJson(self::API.'center/scan')->assertOk()->json();
    }

    public function test_scan_dedupes_by_stable_fingerprint_across_runs_and_audiences(): void
    {
        $admin = $this->admin;
        $mgr = $this->userOf($this->mgr);
        $this->feed($admin, [$this->insight()]);
        $this->feed($mgr, [$this->insight()]);
        $first = $this->scan($admin)['summary'];
        $this->assertSame(1, $first['created']);
        $second = $this->scan($admin)['summary'];
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->scan($mgr);
        $this->signIn($admin);
        $this->assertSame(2, Finding::count(), 'one row per audience + insight key, however many scans ran');
        $fp = FindingService::fingerprint($admin->tenant_id, $admin->id, 'test.late_docs');
        $this->assertSame($fp, FindingService::fingerprint($admin->tenant_id, $admin->id, 'test.late_docs'));
        $this->assertNotSame($fp, FindingService::fingerprint($admin->tenant_id, $mgr->id, 'test.late_docs'));
        $this->assertSame(1, Finding::where('fingerprint', $fp)->count());
        // A changed count updates the same row and records history.
        $this->feed($admin, [$this->insight(count: 9)]);
        $this->assertSame(1, $this->scan($admin)['summary']['updated']);
        $this->signIn($admin);
        $this->assertSame(9, Finding::where('fingerprint', $fp)->value('count'));
    }

    public function test_lifecycle_requires_reasons_and_reopens_or_auto_resolves(): void
    {
        $this->feed($this->admin, [$this->insight(severity: 'warning')]);
        $uuid = $this->scan($this->admin)['items'][0]['uuid'];
        $path = self::API.'center/'.$uuid;
        $this->postJson($path.'/status', ['status' => 'acknowledged'])->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->postJson($path.'/status', ['status' => 'snoozed', 'reason' => 'Waiting'])->assertUnprocessable();          // needs a future date
        $this->postJson($path.'/status', ['status' => 'snoozed', 'snoozed_until' => now()->addDays(3)->toDateString()])->assertUnprocessable(); // needs a reason
        $this->postJson($path.'/status', ['status' => 'snoozed', 'reason' => 'Waiting', 'snoozed_until' => now()->addDays(3)->toIso8601String()])->assertOk();
        $this->postJson($path.'/status', ['status' => 'resolved'])->assertUnprocessable();
        $this->postJson($path.'/status', ['status' => 'resolved', 'reason' => 'Fixed in source'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->postJson($path.'/status', ['status' => 'acknowledged'])->assertUnprocessable();                              // resolved can only reopen
        // Still detected: the next scan reopens it and counts an occurrence.
        $this->assertSame(1, $this->scan($this->admin)['summary']['reopened']);
        $this->getJson($path)->assertOk()->assertJsonPath('status', 'open')->assertJsonPath('occurrences', 2);
        // Dismissed stays dismissed unless severity escalates.
        $this->postJson($path.'/status', ['status' => 'dismissed', 'reason' => 'Accepted risk'])->assertOk();
        $this->assertSame(0, $this->scan($this->admin)['summary']['reopened']);
        $this->feed($this->admin, [$this->insight(severity: 'critical')]);
        $this->assertSame(1, $this->scan($this->admin)['summary']['reopened']);
        // Condition clears: auto-resolved with history.
        $this->feed($this->admin, []);
        $this->assertSame(1, $this->scan($this->admin)['summary']['auto_resolved']);
        $history = $this->getJson($path)->assertOk()->assertJsonPath('status', 'resolved')->json('history');
        $types = array_column($history, 'type');
        foreach (['detected', 'status', 'reopened', 'auto_resolved'] as $t) {
            $this->assertContains($t, $types);
        }
        $this->assertSame('Fixed in source', collect($history)->firstWhere('to', 'resolved')['reason']);
    }

    public function test_findings_are_scoped_to_the_audience_and_employee_360_respects_scope(): void
    {
        $mgr = $this->userOf($this->mgr);
        $this->feed($this->admin, [$this->insight('test.admin_only', 5, 'warning', [['employee_uuid' => $this->outsider->uuid, 'name' => 'Omar Diaz']])]);
        $this->feed($mgr, [$this->insight('test.team_only', 1, 'info', [['employee_uuid' => $this->emp->uuid, 'name' => 'Arun Kumar']])]);
        $adminUuid = $this->scan($this->admin)['items'][0]['uuid'];
        $mgrItems = $this->scan($mgr)['items'];
        $this->assertSame(['test.team_only'], array_column($mgrItems, 'insight_key'));
        $this->signIn($mgr)->getJson(self::API.'center/'.$adminUuid)->assertNotFound();
        $this->getJson(self::API.'center/'.$adminUuid.'/explain')->assertNotFound();
        $this->postJson(self::API.'center/'.$adminUuid.'/status', ['status' => 'acknowledged'])->assertNotFound();
        $this->getJson(self::API.'center')->assertOk()->assertJsonCount(1, 'items');
        // Employee 360 panel: manager sees the finding on a direct report, nothing for an employee outside the scope.
        $this->signIn($mgr);
        $provider = app(IntelligenceEmployee360Provider::class);
        $mine = $provider->sections($mgr, $this->emp, []);
        $this->assertStringContainsString('1 things', json_encode($mine));
        $this->assertSame([], $provider->sections($mgr, $this->outsider, []));
        $this->assertSame([], $provider->sections($mgr, $this->mgr, []), 'no findings about this employee: section omitted');
        $this->getJson(self::API.'employees/'.$this->outsider->uuid.'/time-story')->assertNotFound();
        $this->getJson(self::API.'employees/'.$this->emp->uuid.'/time-story')->assertOk()->assertJsonPath('events.0.source', fn ($v) => is_string($v));
        $this->assertArrayHasKey('intelligence', app(\App\Domain\People\Employee360\Employee360::class)->providers());
    }

    public function test_explain_output_lists_inputs_rule_threshold_as_of_scope_and_suppressed(): void
    {
        $this->feed($this->admin, [$this->insight(count: 12, sample: [['employee_uuid' => $this->emp->uuid, 'name' => 'Arun Kumar']])]);
        $uuid = $this->scan($this->admin)['items'][0]['uuid'];
        $x = $this->getJson(self::API.'center/'.$uuid.'/explain')->assertOk()->json('explain');
        $this->assertSame(12, $x['inputs']['matching_records']);
        $this->assertSame(1, $x['inputs']['sample_shown']);
        $this->assertSame('Stub rule', $x['rule']);
        $this->assertSame('count >= 1', $x['threshold']);
        $this->assertSame('2026-10-07', $x['as_of']['tenant_date']);
        $this->assertContains('tenant', $x['scope']['data_scopes']);
        $this->assertSame(11, $x['suppressed']['records_beyond_sample']);
        $this->assertTrue($x['deterministic']);
    }

    public function test_actions_create_one_task_bulk_acknowledge_and_audit(): void
    {
        $this->feed($this->admin, [$this->insight('test.a'), $this->insight('test.b', 2, 'critical')]);
        $items = $this->scan($this->admin)['items'];
        $this->assertSame('critical', $items[0]['severity'], 'sorted by severity');
        $uuid = $items[0]['uuid'];
        $this->postJson(self::API.'center/'.$uuid.'/task')->assertOk()->assertJsonPath('created', true)->assertJsonPath('finding.has_task', true);
        $this->postJson(self::API.'center/'.$uuid.'/task')->assertOk()->assertJsonPath('created', false);
        $this->signIn($this->admin);
        $this->assertSame(1, WorkItem::where('source_module', 'intelligence')->count());
        $this->assertSame('high', WorkItem::where('source_module', 'intelligence')->value('priority'));
        $r = $this->postJson(self::API.'center/bulk-acknowledge', ['uuids' => array_column($items, 'uuid')])->assertOk()->json();
        $this->assertSame(2, $r['acknowledged']);
        $this->postJson(self::API.'center/'.$uuid.'/status', ['status' => 'resolved', 'reason' => 'Done'])->assertOk();
        $this->signIn($this->admin);
        $this->assertSame('completed', WorkItem::where('source_module', 'intelligence')->value('status'), 'resolving completes the linked task');
        $this->assertTrue(DB::table('audit_logs')->where('module', 'intelligence')->where('action', 'intelligence.finding.resolved')->exists());
        // Team member may not see scenarios or settings.
        $this->signIn($this->userOf($this->emp))->getJson(self::API.'scenarios')->assertForbidden();
        $this->putJson(self::API.'settings', ['risk_signals_enabled' => true, 'min_cohort' => 5])->assertForbidden();
    }

    public function test_scenarios_are_read_only_and_stored(): void
    {
        $scenarios = app(ScenarioService::class);
        // A misbehaving source that tries to write into another domain: the write must not survive.
        $scenarios->register('rogue_writer', 'intelligence.view', function (User $v) {
            WorkItem::create(['assignee_id' => $v->id, 'title' => 'ROGUE', 'source_module' => 'rogue']);
            DB::table('employees')->where('tenant_id', $v->tenant_id)->update(['notice_period_days' => 999]);

            return ['attempted' => true];
        });
        $before = ['work' => WorkItem::count(), 'notice' => DB::table('employees')->where('notice_period_days', 999)->count()];
        $run = $this->postJson(self::API.'scenarios', ['kind' => 'rogue_writer', 'title' => 'Rogue'])->assertCreated()->assertJsonPath('read_only', true)->json();
        $this->signIn($this->admin);
        $this->assertSame($before['work'], WorkItem::count());
        $this->assertSame($before['notice'], DB::table('employees')->where('notice_period_days', 999)->count());
        $this->assertSame(1, DB::table('intelligence_scenario_runs')->count(), 'the run itself is stored');
        $this->getJson(self::API.'scenarios/'.$run['uuid'])->assertOk()->assertJsonPath('kind', 'rogue_writer');
        // Headcount plan is computed from the scoped employee list.
        $res = $this->postJson(self::API.'scenarios', ['kind' => 'headcount_plan', 'params' => ['months' => 3, 'hires_per_month' => 2, 'exits_per_month' => 1]])->assertCreated()->json('result');
        $this->assertSame(3, $res['current_headcount']);
        $this->assertSame(6, $res['projected_headcount']);
        $this->postJson(self::API.'scenarios', ['kind' => 'nope'])->assertUnprocessable();
        // Manager may run it but sees only their reach (self + direct report).
        $this->signIn($this->userOf($this->mgr));
        $this->assertSame(2, $this->postJson(self::API.'scenarios', ['kind' => 'headcount_plan'])->assertCreated()->json('result.current_headcount'));
        $this->postJson(self::API.'scenarios', ['kind' => 'payroll_simulation'])->assertForbidden();
        // Another viewer cannot open this run.
        $this->signIn($this->admin);
        $other = $this->postJson(self::API.'scenarios', ['kind' => 'headcount_plan'])->json('uuid');
        $this->signIn($this->userOf($this->mgr))->getJson(self::API.'scenarios/'.$other)->assertNotFound();
    }

    public function test_risk_signals_are_disabled_by_default_and_small_cohorts_are_suppressed(): void
    {
        $this->getJson(self::API.'risk-signals')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('cohorts', []);
        $this->putJson(self::API.'settings', ['risk_signals_enabled' => true, 'min_cohort' => 2])->assertUnprocessable();
        $this->putJson(self::API.'settings', ['risk_signals_enabled' => true, 'min_cohort' => 5])->assertOk()->assertJsonPath('min_cohort', 5);
        // 3 employees in one department only (below 5): nothing may be shown.
        $r = $this->signIn($this->admin)->getJson(self::API.'risk-signals')->assertOk()->json();
        $this->assertTrue($r['enabled']);
        $this->assertSame([], $r['cohorts']);
        $this->assertNotEmpty($r['human_review_notice']);
        $this->assertStringContainsString('not predictions', $r['human_review_notice']);
        // Grow department 0 to 5 people (all joined within 12 months): cohort appears, counts below the threshold are masked.
        $this->signIn($this->admin);
        $this->hire('Eve One');
        $this->hire('Eve Two');
        $r = $this->signIn($this->admin)->getJson(self::API.'risk-signals')->assertOk()->json();
        $this->assertCount(1, $r['cohorts']);
        $c = $r['cohorts'][0];
        $this->assertSame(5, $c['size']);
        $this->assertSame(5, $c['indicators']['early_tenure']);
        $this->assertSame(0, $c['indicators']['performance_flag']);
        $this->assertStringNotContainsString('Eve', json_encode($r), 'no individual is identifiable');
        // A single flagged person inside a cohort is masked as "<5".
        $this->signIn($this->admin);
        DB::table('employees')->where('id', $this->emp->id)->update(['probation_extensions' => 1]);
        $c = $this->getJson(self::API.'risk-signals')->json('cohorts.0');
        $this->assertSame('<5', $c['indicators']['in_probation']);
        // Manager (reach below the threshold) receives no cohorts at all.
        $this->assertSame([], $this->signIn($this->userOf($this->mgr))->getJson(self::API.'risk-signals')->assertOk()->json('cohorts'));
    }

    public function test_tenant_isolation(): void
    {
        $this->feed($this->admin, [$this->insight()]);
        $uuid = $this->scan($this->admin)['items'][0]['uuid'];
        $beta = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->feed($beta, [$this->insight()]);
        $this->signIn($beta)->getJson(self::API.'center')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson(self::API.'center/'.$uuid)->assertNotFound();
        $this->postJson(self::API.'center/'.$uuid.'/status', ['status' => 'acknowledged'])->assertNotFound();
        $this->postJson(self::API.'center/scan')->assertOk()->assertJsonPath('summary.created', 1);
        $this->context($beta->tenant_id);
        $this->assertSame(1, Finding::count());
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Finding::count());
        $this->assertSame(2, DB::table('intelligence_findings')->count());
        $this->assertNotSame(DB::table('intelligence_findings')->pluck('fingerprint')->unique()->count(), 1);
    }

    public function test_scan_command_is_idempotent_per_tenant(): void
    {
        $this->feed($this->admin, [$this->insight()]);
        $this->artisan('intelligence:scan', ['--tenant' => $this->admin->tenant_id])->assertSuccessful();
        $this->artisan('intelligence:scan', ['--tenant' => $this->admin->tenant_id])->assertSuccessful();
        $this->assertSame(1, DB::table('intelligence_findings')->where('tenant_id', $this->admin->tenant_id)->where('insight_key', 'test.late_docs')->count());
        $this->assertSame(1, DB::table('intelligence_finding_events')->where('type', 'detected')->count());
    }
}
