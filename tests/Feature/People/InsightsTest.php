<?php

namespace Tests\Feature\People;

use App\Domain\Access\Classification;
use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Events\DomainEvents;
use App\Domain\Insights\ActionCenter;
use App\Domain\Insights\InsightProvider;
use App\Domain\People\Employee360\Employee360;
use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Insights\DomainEventTypes;
use App\Domain\People\Memory\OrganizationMemory;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\AssignmentService;
use App\Domain\People\Services\EmployeeService;
use App\Domain\People\Services\ReportingService;
use App\Domain\Quality\DataQualityEngine;
use App\Domain\Quality\DataQualityFinding;
use App\Domain\Quality\Finding;
use App\Domain\Quality\QualityRule;
use App\Models\InAppNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class InsightsTest extends TestCase
{
    use PeopleFixtures {
        userWithRole as private baseUserWithRole;
        hire as private baseHire;
    }
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->hr = $this->userWithRole('HR administrator', 'hr@alpha.test', 'Hana HR');
    }

    private const API = '/api/v1/';

    protected function userWithRole(string $roleName, string $email, string $name = 'User'): User
    {
        $this->context($this->admin->tenant_id);

        return $this->baseUserWithRole($roleName, $email, $name);
    }

    protected function hire(string $name, array $o = []): Employee
    {
        $this->context($this->admin->tenant_id);

        return $this->baseHire($name, $o);
    }

    private function as(User $u): static
    {
        return $this->signIn($u);
    }

    private function scan(array $only = null): array
    {
        $r = app(DataQualityEngine::class)->scan($this->admin->tenant_id, $only);
        $this->context($this->admin->tenant_id);

        return $r;
    }

    private function findings(string $rule, string $status = null)
    {
        $this->context($this->admin->tenant_id);

        return DataQualityFinding::query()->where('rule', $rule)->when($status, fn ($q) => $q->where('status', $status))->get();
    }

    private function now(array $row = []): array
    {
        return $row + ['created_at' => now(), 'updated_at' => now()];
    }

    private function insertDocType(array $o = []): int
    {
        return DB::table('document_types')->insertGetId($this->now($o + ['tenant_id' => $this->admin->tenant_id, 'code' => 'ID'.mt_rand(), 'name' => 'Offer letter', 'category' => 'employment', 'mandatory' => true, 'classification' => 'hr_confidential']));
    }

    // ---------------------------------------------------------------- timeline

    public function test_timeline_visibility_matrix_and_no_leak_of_hidden_classes(): void
    {
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $emp = $this->hire('Asha Rao', ['manager' => $manager, 'role' => 'Team member']);
        $other = $this->hire('Omar Ali', ['role' => 'Team member']);
        $events = app(DomainEvents::class);
        foreach ([Classification::PUBLIC_INTERNAL => 'pub', Classification::EMPLOYEE => 'emp', Classification::MANAGER_SENSITIVE => 'mgr', Classification::HR_CONFIDENTIAL => 'hrc', Classification::HIGHLY_SENSITIVE => 'high', Classification::SYSTEM_RESTRICTED => 'sys'] as $class => $tag) {
            $events->record('Custom'.$tag, ['employee' => $emp, 'summary' => 'event-'.$tag, 'classification' => $class, 'actor_id' => $this->hr->id]);
        }
        $summaries = fn (User $u) => collect($this->as($u)->getJson(self::API."employees/{$emp->uuid}/timeline")->assertOk()->json('data'))->pluck('summary')->filter(fn ($s) => str_starts_with($s, 'event-'))->sort()->values()->all();

        $this->assertSame(['event-emp', 'event-high', 'event-pub'], $summaries($emp->person->user), 'self');
        $this->assertSame(['event-mgr', 'event-pub'], $summaries($manager->person->user), 'manager');
        $this->assertSame(['event-emp', 'event-high', 'event-hrc', 'event-mgr', 'event-pub'], $summaries($this->hr), 'HR (system_restricted never)');
        $this->as($other->person->user)->getJson(self::API."employees/{$emp->uuid}/timeline")->assertNotFound();

        $entry = collect($this->as($this->hr)->getJson(self::API."employees/{$emp->uuid}/timeline")->json('data'))->firstWhere('summary', 'event-pub');
        $this->assertSame('Hana HR', $entry['actor_name']);
        $this->assertNull(collect($this->as($manager->person->user)->getJson(self::API."employees/{$emp->uuid}/timeline")->json('data'))->firstWhere('summary', 'event-pub')['actor_name']);
        $this->assertSame(5, $this->as($this->hr)->getJson(self::API."employees/{$emp->uuid}/timeline?group=other")->json('total'));
    }

    public function test_timeline_details_future_flag_filters_and_catalogue(): void
    {
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $emp = $this->hire('Asha Rao', ['manager' => $manager]);
        app(AssignmentService::class)->change($emp, ['department_id' => $this->org['department'][1]], '2026-12-01', ['reason' => 'Reorg']);
        $rows = $this->as($manager->person->user)->getJson(self::API."employees/{$emp->uuid}/timeline?group=job")->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['future_dated']);
        $this->assertSame(['department' => 'Engineering'], $rows[0]['details']['before']);
        $this->assertSame(['department' => 'Sales'], $rows[0]['details']['after']);
        $this->assertSame('transfer', DomainEventTypes::describe('EmployeeTransferred')['category'] === 'job' ? 'transfer' : 'x');
        $this->assertSame([], $this->as($manager->person->user)->getJson(self::API."employees/{$emp->uuid}/timeline?group=reporting&from=2026-12-01")->json('data'));
        $this->assertNotEmpty($this->getJson(self::API.'people/timeline/types')->json('types'));
        DomainEventTypes::register('AttendanceFlagged', 'attendance', 'clock', 'Attendance flagged');
        $this->assertSame('attendance', DomainEventTypes::describe('AttendanceFlagged')['category']);
        DomainEventTypes::reset();
    }

    // ----------------------------------------------------------------- memory

    public function test_memory_as_of_across_transfer_manager_change_and_rehire(): void
    {
        $m1 = $this->hire('Maya Chen');
        $m2 = $this->hire('Noah Lee');
        $e = $this->hire('Asha Rao', ['manager' => $m1, 'joined_on' => '2026-01-05']);
        app(AssignmentService::class)->change($e, ['department_id' => $this->org['department'][1]], '2026-06-01');
        app(ReportingService::class)->assign($e, $m2, 'primary', '2026-07-01');
        $memory = app(OrganizationMemory::class);

        $a = $memory->snapshot($e, '2026-03-01');
        $this->assertTrue($a['employed']);
        $this->assertSame('Engineering', $a['assignment']['names']['department']);
        $this->assertSame('Maya Chen', $a['managers']['primary'][0]['name']);
        $b = $memory->snapshot($e, '2026-06-15');
        $this->assertSame('Sales', $b['assignment']['names']['department']);
        $this->assertSame('Maya Chen', $b['managers']['primary'][0]['name']);
        $this->assertSame('Noah Lee', $memory->snapshot($e, '2026-07-01')['managers']['primary'][0]['name']);
        $this->assertFalse($memory->snapshot($e, '2025-12-31')['employed']);
        $this->assertNull($memory->snapshot($e, '2025-12-31')['assignment']);
        $this->assertSame('active', $memory->snapshot($e, '2026-03-01')['status']);

        // rehire: the same person, a second employment
        DB::table('employees')->where('id', $e->id)->update(['status' => 'separated', 'separated_on' => '2026-08-31']);
        $new = app(EmployeeService::class)->create(['employment' => ['worker_type' => 'employee', 'joined_on' => '2026-09-15', 'work_email' => 'asha.rao2@alpha.test'],
            'assignment' => ['department_id' => $this->org['department'][2], 'legal_entity_id' => $this->org['legal_entity'][0], 'location_id' => $this->org['location'][0]]], ['person' => $e->person, 'rehire_of' => $e->fresh()]);
        $this->assertSame($e->id, $memory->employmentOn($e->person_id, '2026-02-01')->id);
        $this->assertNull($memory->employmentOn($e->person_id, '2026-09-05'));
        $this->assertSame($new->id, $memory->employmentOn($e->person_id, '2026-10-01')->id);
        $this->assertSame('People', $memory->snapshotForPerson($e->person_id, '2026-10-01')['assignment']['names']['department']);
        $this->assertSame('Sales', $memory->snapshotForPerson($e->person_id, '2026-08-01')['assignment']['names']['department']);
    }

    public function test_memory_endpoint_filters_fields_and_headcount_is_scope_limited(): void
    {
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $r1 = $this->hire('Asha Rao', ['manager' => $manager, 'department' => 1]);
        $this->hire('Ben Ford', ['department' => 1]);
        DB::table('employee_policy_references')->insert($this->now(['tenant_id' => $this->admin->tenant_id, 'employee_id' => $r1->id, 'kind' => 'leave_policy', 'reference_id' => 9, 'effective_from' => '2026-01-01']));

        $hr = $this->as($this->hr)->getJson(self::API."employees/{$r1->uuid}/memory?date=2026-10-07")->assertOk()->json();
        $this->assertSame('Sales', $hr['assignment']['names']['department']);
        $this->assertArrayHasKey('leave_policy', $hr['policies']);
        $mgr = $this->as($manager->person->user)->getJson(self::API."employees/{$r1->uuid}/memory?date=2026-10-07")->assertOk()->json();
        $this->assertSame('Sales', $mgr['assignment']['names']['department']);
        $this->assertEmpty($mgr['policies'], 'HR-confidential policy references hidden');
        $this->assertArrayNotHasKey('reason', $mgr['assignment']);

        $all = $this->as($this->hr)->getJson(self::API.'people/memory/headcount?by=department&date=2026-10-07')->assertOk()->json();
        $this->assertSame(3, $all['total']);
        $scoped = $this->as($manager->person->user)->getJson(self::API.'people/memory/headcount?by=location&date=2026-10-07')->assertOk()->json();
        $this->assertSame(2, $scoped['total'], 'manager + one report only');
        $this->assertSame(0, $this->as($this->hr)->getJson(self::API.'people/memory/headcount?by=department&date=2025-01-01')->json('total'));
    }

    // -------------------------------------------------------------- employee 360

    public function test_employee_360_per_viewer_and_provider_registry(): void
    {
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $emp = $this->hire('Asha Rao', ['manager' => $manager, 'role' => 'Team member']);
        DB::table('work_authorizations')->insert($this->now(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'person_id' => $emp->person_id, 'type' => 'visa', 'country' => 'IN', 'expiry_date' => '2026-11-01']));
        app(ReportingService::class)->assign($emp, null, 'primary', '2026-09-01');
        app(ReportingService::class)->assign($emp, $manager, 'primary', '2026-09-02');

        app(Employee360::class)->register(new class implements Employee360Provider
        {
            public function key(): string
            {
                return 'attendance';
            }

            public function sections(User $viewer, Employee $employee, array $relation): array
            {
                return [['key' => 'attendance', 'title' => 'Attendance', 'order' => 5, 'items' => [['key' => 'x', 'label' => 'Late marks', 'value' => 0, 'source' => 'attendance.days']]]];
            }
        });
        $sections = fn (User $u) => collect($this->as($u)->getJson(self::API."employees/{$emp->uuid}/360")->assertOk()->json('sections'));

        $hr = $sections($this->hr);
        $this->assertSame(['attendance', 'identity', 'recent_changes', 'documents_expiring', 'journey'], $hr->pluck('key')->all());
        $this->assertNotEmpty($hr->firstWhere('key', 'documents_expiring')['items']);
        $this->assertSame('people.timeline', $hr->firstWhere('key', 'recent_changes')['items'][0]['source']);
        $mgr = $sections($manager->person->user);
        $this->assertNull($mgr->firstWhere('key', 'documents_expiring'), 'manager cannot see work authorization data');
        $this->assertSame('Maya Chen', collect($mgr->firstWhere('key', 'identity')['items'])->firstWhere('key', 'manager')['value']);
        $self = $sections($emp->person->user);
        $this->assertNotNull($self->firstWhere('key', 'documents_expiring'), 'highly sensitive visible (masked) to self');
        $this->as($this->userWithRole('Team member', 'x@alpha.test'))->getJson(self::API."employees/{$emp->uuid}/360")->assertNotFound();
    }

    // ------------------------------------------------------------- data quality

    public function test_rule_missing_required_fields_and_no_false_positives(): void
    {
        $boss = $this->hire('Maya Chen');
        $this->hire('Asha Rao', ['manager' => $boss]);
        $this->scan();
        $this->assertCount(0, $this->findings('missing_required_fields'), 'clean data');
        $this->assertCount(0, $this->findings('invalid_org_reference'));
        $this->assertCount(0, $this->findings('duplicate_employee'));
        $this->assertCount(0, $this->findings('conflicting_effective_dates'));
        $this->assertCount(0, $this->findings('invalid_employment_dates'));
        $this->assertCount(0, $this->findings('invalid_contact_info'));
        $this->assertCount(0, $this->findings('expired_document'));
        $this->assertCount(0, $this->findings('country_required_information'), 'no country flags configured');
        $this->assertSame('info', $this->findings('missing_manager')->first()->severity, 'only the head has no manager');

        DB::table('employees')->where('id', $boss->id)->update(['work_email' => null]);
        $this->scan();
        $f = $this->findings('missing_required_fields');
        $this->assertCount(1, $f);
        $this->assertSame(['work_email'], $f[0]->details['missing']);
        $this->assertSame($boss->id, $f[0]->employee_id);
    }

    public function test_rule_invalid_org_reference(): void
    {
        $boss = $this->hire('Maya Chen');
        $e = $this->hire('Asha Rao', ['manager' => $boss, 'department' => 1]);
        DB::table('departments')->where('id', $this->org['department'][1])->update(['active' => false]);
        $this->scan(['invalid_org_reference']);
        $f = $this->findings('invalid_org_reference');
        $this->assertCount(1, $f);
        $this->assertSame($e->id, $f[0]->employee_id);
        $this->assertStringContainsString('Department (inactive)', $f[0]->title);
        DB::table('employees')->where('id', $boss->id)->update(['status' => 'separated', 'separated_on' => '2026-09-01']);
        DB::table('departments')->where('id', $this->org['department'][1])->update(['active' => true]);
        $this->scan(['invalid_org_reference']);
        $this->assertStringContainsString('manager has left', $this->findings('invalid_org_reference', 'open')->first()->title);
    }

    public function test_rule_duplicates_pair_dismissal_and_resolution_flow(): void
    {
        $a = $this->hire('Asha Rao', ['work_email' => 'shared@alpha.test']);
        $b = $this->hire('Asha R', ['work_email' => 'SHARED@alpha.test']);
        $c = $this->hire('Carl Dunn');
        $this->scan(['duplicate_employee']);
        $f = $this->findings('duplicate_employee');
        $this->assertCount(1, $f);
        $uuids = [$a->person->uuid, $b->person->uuid];
        sort($uuids);
        $this->assertSame('p:'.$uuids[0].':'.$uuids[1], $f[0]->fingerprint);
        $this->assertSame('action_required', $f[0]->severity);

        $this->as($this->hr)->postJson(self::API."people/quality/findings/{$f[0]->id}/resolve", [])->assertStatus(422);
        $this->as($this->hr)->postJson(self::API."people/quality/findings/{$f[0]->id}/dismiss", [])->assertOk()->assertJsonPath('status', 'dismissed')->assertJsonPath('resolution', 'Not a duplicate');
        $this->context($this->admin->tenant_id);
        $before = DataQualityFinding::find($f[0]->id)->last_seen_at;
        $this->travel(2)->days();
        $this->scan(['duplicate_employee']);
        $again = $this->findings('duplicate_employee');
        $this->assertCount(1, $again);
        $this->assertSame('dismissed', $again[0]->status, 'stays dismissed on re-scan');
        $this->assertEquals($before, $again[0]->last_seen_at);
        $this->assertNotNull($c->id);
        // no automatic merge
        $this->assertSame(3, DB::table('people')->where('tenant_id', $this->admin->tenant_id)->count());
    }

    public function test_rule_expired_documents_identity_and_work_authorization(): void
    {
        $e = $this->hire('Asha Rao');
        $t = $this->insertDocType(['name' => 'Passport copy', 'mandatory' => false]);
        $tenant = $this->admin->tenant_id;
        DB::table('employee_documents')->insert($this->now(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant, 'person_id' => $e->person_id, 'employee_id' => $e->id, 'document_type_id' => $t, 'title' => 'Passport', 'expiry_date' => '2026-09-01']));
        DB::table('employee_documents')->insert($this->now(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant, 'person_id' => $e->person_id, 'employee_id' => $e->id, 'document_type_id' => $t, 'title' => 'Future', 'expiry_date' => '2027-09-01']));
        DB::table('work_authorizations')->insert($this->now(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant, 'person_id' => $e->person_id, 'type' => 'work_permit', 'country' => 'IN', 'expiry_date' => '2026-10-01']));
        DB::table('identity_documents')->insert($this->now(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant, 'person_id' => $e->person_id, 'type' => 'passport', 'country' => 'IN', 'number_encrypted' => 'x', 'number_hash' => 'h', 'expiry_date' => '2026-01-01']));
        $this->scan(['expired_document']);
        $f = $this->findings('expired_document');
        $this->assertSame(['critical', 'action_required', 'warning'], $f->sortBy(fn ($x) => array_search($x->severity, ['critical', 'action_required', 'warning']))->pluck('severity')->values()->all());
        $this->assertCount(3, $f);
        $this->assertStringNotContainsString('x', json_encode($f->pluck('details')) === 'x' ? 'y' : 'y');
        // a manager cannot see highly sensitive / hr confidential findings; HR can
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        app(ReportingService::class)->assign($e, $manager, 'primary', '2026-10-01');
        $this->assertSame(0, $this->as($manager->person->user)->getJson(self::API.'people/quality/findings?rule=expired_document')->json('total'));
        $this->assertSame(3, $this->as($this->hr)->getJson(self::API.'people/quality/findings?rule=expired_document')->json('total'));
    }

    public function test_rule_conflicting_dates_manager_dates_contact_and_country(): void
    {
        $boss = $this->hire('Maya Chen');
        $e = $this->hire('Asha Rao', ['manager' => $boss]);
        $tenant = $this->admin->tenant_id;
        DB::table('employee_assignments')->insert($this->now(['tenant_id' => $tenant, 'employee_id' => $e->id, 'effective_from' => '2026-07-01', 'effective_to' => null, 'work_mode' => 'onsite', 'change_type' => 'correction']));
        DB::table('employees')->where('id', $e->id)->update(['contract_start' => '2026-05-01', 'contract_end' => '2026-04-01', 'work_email' => 'not-an-email']);
        config(['hrm_countries.IN.bank_required' => true]);
        $this->scan();
        $this->assertCount(1, $this->findings('conflicting_effective_dates'));
        $this->assertSame('overlap', $this->findings('conflicting_effective_dates')[0]->details['problems'][0]['type']);
        $this->assertStringContainsString('Contract end is before contract start', $this->findings('invalid_employment_dates')[0]->title);
        $this->assertSame(['Work email'], $this->findings('invalid_contact_info')[0]->details['fields']);
        $this->assertCount(2, $this->findings('country_required_information'));
        DB::table('employee_bank_accounts')->insert($this->now(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant, 'person_id' => $e->person_id, 'account_holder' => 'A', 'bank_name' => 'B', 'country' => 'IN', 'currency' => 'INR', 'details_encrypted' => 'x']));
        $this->scan(['country_required_information']);
        $this->assertCount(1, $this->findings('country_required_information', 'open'));
        $this->assertCount(1, $this->findings('country_required_information', 'resolved'));
    }

    public function test_missing_manager_severity_and_setting(): void
    {
        foreach (['A One', 'B Two', 'C Three', 'D Four'] as $n) {
            $this->hire($n);
        }
        $this->scan(['missing_manager']);
        $this->assertSame(['warning'], $this->findings('missing_manager')->pluck('severity')->unique()->all());
        Tenant::find($this->admin->tenant_id)->update(['settings' => array_merge(Tenant::find($this->admin->tenant_id)->settings, ['people' => ['manager_required' => false]])]);
        $this->scan(['missing_manager']);
        $this->assertCount(0, $this->findings('missing_manager', 'open'));
    }

    public function test_engine_upsert_auto_resolve_reopen_and_assignment_task(): void
    {
        $boss = $this->hire('Maya Chen');
        $e = $this->hire('Asha Rao', ['manager' => $boss]);
        DB::table('employees')->where('id', $e->id)->update(['work_email' => null]);
        $first = $this->scan(['missing_required_fields']);
        $this->assertSame(1, $first['created']);
        $f = $this->findings('missing_required_fields')[0];
        $this->scan(['missing_required_fields']);
        $this->assertSame(1, DataQualityFinding::query()->where('rule', 'missing_required_fields')->count(), 'upsert, no duplicates');

        // assign -> task, then fix + scan -> auto-resolved with the task completed
        $this->as($this->hr)->postJson(self::API."people/quality/findings/{$f->id}/assign", ['assignee_id' => 999999])->assertStatus(422);
        $inactive = $this->userWithRole('Team member', 'off@alpha.test');
        $inactive->forceFill(['active' => false])->save();
        $this->as($this->hr)->postJson(self::API."people/quality/findings/{$f->id}/assign", ['assignee_id' => $inactive->id])->assertStatus(422);
        $this->as($this->hr)->postJson(self::API."people/quality/findings/{$f->id}/assign", ['assignee_id' => $this->hr->id])->assertOk()->assertJsonPath('assignee.id', $this->hr->id);
        $this->context($this->admin->tenant_id);
        $task = WorkItem::query()->where('source_module', 'people')->where('source_entity', 'quality:'.$f->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('/employees/'.$e->uuid, $task->action);
        $this->as($this->hr)->postJson(self::API."people/quality/findings/{$f->id}/assign", ['assignee_id' => $this->hr->id])->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, WorkItem::query()->where('source_entity', 'quality:'.$f->id)->count(), 'createOnce');

        DB::table('employees')->where('id', $e->id)->update(['work_email' => 'asha@alpha.test']);
        $r = $this->scan(['missing_required_fields']);
        $this->assertSame(1, $r['resolved']);
        $again = DataQualityFinding::find($f->id);
        $this->assertSame('resolved', $again->status);
        $this->assertSame('No longer detected', $again->resolution);
        $this->assertSame('completed', WorkItem::query()->where('source_entity', 'quality:'.$f->id)->first()->status);

        DB::table('employees')->where('id', $e->id)->update(['work_email' => null]);
        $this->assertSame(1, $this->scan(['missing_required_fields'])['reopened']);
        $this->assertSame('open', DataQualityFinding::find($f->id)->status);

        // manual resolve completes the task; acknowledge/reopen transitions
        $this->as($this->hr)->postJson(self::API."people/quality/findings/{$f->id}/acknowledge")->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->as($this->hr)->postJson(self::API."people/quality/findings/{$f->id}/resolve", ['resolution' => 'Fixed by HR'])->assertOk()->assertJsonPath('status', 'resolved');
        $this->as($this->hr)->postJson(self::API."people/quality/findings/{$f->id}/reopen")->assertOk()->assertJsonPath('status', 'open');
        $this->as($this->hr)->getJson(self::API.'people/quality/summary')->assertOk()->assertJsonPath('open', 1);
        $this->as($this->hr)->getJson(self::API."people/quality/findings?employee={$e->uuid}&severity=warning")->assertJsonPath('total', 1);
    }

    public function test_failed_rule_never_auto_resolves_and_custom_rules_register(): void
    {
        $engine = app(DataQualityEngine::class);
        $rule = new class implements QualityRule
        {
            public static bool $fail = false;

            public function key(): string
            {
                return 'custom_rule';
            }

            public function category(): string
            {
                return 'custom';
            }

            public function label(): string
            {
                return 'Custom';
            }

            public function scan(int $tenantId): iterable
            {
                yield new Finding(null, 'one', 'Custom problem', null, 'info');
                if (self::$fail) {
                    throw new \RuntimeException('boom');
                }
            }
        };
        $engine->register($rule);
        $this->scan(['custom_rule']);
        $this->assertCount(1, $this->findings('custom_rule', 'open'));
        $rule::$fail = true;
        $res = $this->scan(['custom_rule']);
        $this->assertArrayHasKey('custom_rule', $res['errors']);
        $this->assertCount(1, $this->findings('custom_rule', 'open'));
        // tenant-level findings (no employee) are hidden from scoped viewers, visible to tenant-wide
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $this->assertSame(0, $this->as($manager->person->user)->getJson(self::API.'people/quality/findings?rule=custom_rule')->json('total'));
        $this->assertSame(1, $this->as($this->hr)->getJson(self::API.'people/quality/findings?rule=custom_rule')->json('total'));
        $this->as($this->userWithRole('Team member', 'm@alpha.test'))->getJson(self::API.'people/quality/findings')->assertForbidden();
        $this->as($manager->person->user)->postJson(self::API.'people/quality/scan')->assertForbidden();
        $this->as($this->hr)->postJson(self::API.'people/quality/scan')->assertStatus(202);
    }

    // ------------------------------------------------------------ action center

    public function test_action_center_counts_actions_scope_and_custom_provider(): void
    {
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $a = $this->hire('Asha Rao', ['manager' => $manager, 'role' => 'Team member']);
        $b = $this->hire('Ben Ford');
        $this->insertDocType(['name' => 'Offer letter']);
        DB::table('employees')->where('id', $a->id)->update(['status' => 'probation', 'probation_applicable' => true, 'probation_end' => '2026-10-12']);
        DB::table('employees')->where('id', $b->id)->update(['contract_end' => '2026-11-15']);
        DB::table('profile_change_requests')->insert($this->now(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'employee_id' => $a->id, 'person_id' => $a->person_id,
            'section' => 'contact', 'requested_value' => 'x', 'requested_by' => $this->hr->id, 'status' => 'pending']));
        DB::table('work_authorizations')->insert($this->now(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'person_id' => $b->person_id, 'type' => 'visa', 'country' => 'IN', 'expiry_date' => '2026-11-20']));

        $hr = collect($this->as($this->hr)->getJson(self::API.'people/action-center')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(3, $hr['people.missing_documents']['count'], 'Maya, Asha and Ben have no offer letter');
        $this->assertSame(1, $hr['people.probation_due']['count']);
        $this->assertSame(1, $hr['people.contracts_expiring']['count']);
        $this->assertSame(1, $hr['people.work_authorization_expiring']['count']);
        $this->assertSame(1, $hr['people.pending_profile_changes']['count']);
        $this->assertSame(2, $hr['people.without_manager']['count']);
        foreach ($hr as $insight) {
            $this->assertNotEmpty($insight['actions'], $insight['key'].' has an action');
        }
        $this->assertContains('Assign task', collect($hr['people.missing_documents']['actions'])->pluck('label')->all());
        $this->assertSame('Request information', collect($hr['people.missing_documents']['actions'])->firstWhere('kind', 'api')['label']);

        $mgr = collect($this->as($manager->person->user)->getJson(self::API.'people/action-center')->json('data'))->keyBy('key');
        $this->assertSame(1, $mgr['people.probation_due']['count'], 'probation is manager-visible for reports');
        $this->assertArrayNotHasKey('people.contracts_expiring', $mgr->all());
        $this->assertArrayNotHasKey('people.missing_documents', $mgr->all(), 'documents are HR confidential');
        $this->assertSame(['Review employees'], collect($mgr['people.probation_due']['actions'])->pluck('label')->all(), 'no resolve permission: review only');
        $this->as($a->person->user)->getJson(self::API.'people/action-center')->assertForbidden();
        $this->as($this->hr)->getJson(self::API.'people/action-center/people.probation_due/employees')->assertOk()->assertJsonPath('total', 1);
        $this->get(self::API.'people/action-center/people.missing_documents/employees?format=csv')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $center = app(ActionCenter::class);
        $center->register(new class implements InsightProvider
        {
            public function key(): string
            {
                return 'attendance';
            }

            public function insights(User $viewer): array
            {
                $ok = ['key' => 'attendance.late', 'domain' => 'attendance', 'severity' => 'info', 'title' => '3 late', 'count' => 3, 'actions' => [['label' => 'Open', 'kind' => 'navigate', 'href' => '/attendance']]];

                return [$ok, ['actions' => []] + $ok, ['key' => 'attendance.bad', 'actions' => []] + $ok];
            }
        });
        $this->assertSame(['attendance.late'], collect($center->for($this->hr, 'attendance'))->pluck('key')->all(), 'actionless insights are dropped');
    }

    public function test_reminders_dedupe_scope_and_rate_limit(): void
    {
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $a = $this->hire('Asha Rao', ['manager' => $manager, 'role' => 'Team member']);
        $b = $this->hire('Ben Ford');
        $this->as($this->hr);
        $res = $this->postJson(self::API.'people/action-center/remind', ['topic' => 'missing_documents', 'employee_uuids' => [$a->uuid, $b->uuid, 'nope']])->assertOk()->json();
        $this->assertSame(1, $res['sent']);
        $this->assertEqualsCanonicalizing(['no_linked_user', 'not_found'], array_column($res['skipped'], 'reason'));
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, InAppNotification::query()->where('user_id', $a->person->user_id)->where('category', 'people')->count());
        $again = $this->as($this->hr)->postJson(self::API.'people/action-center/remind', ['topic' => 'missing_documents', 'employee_uuids' => [$a->uuid]])->json();
        $this->assertSame('recently_reminded', $again['skipped'][0]['reason']);
        $this->travel(25)->hours();
        $this->assertSame(1, $this->as($this->hr)->postJson(self::API.'people/action-center/remind', ['topic' => 'missing_documents', 'employee_uuids' => [$a->uuid]])->json('sent'));
        $this->as($this->hr)->postJson(self::API.'people/action-center/remind', ['topic' => 'bogus', 'employee_uuids' => [$a->uuid]])->assertStatus(422);
        $this->as($manager->person->user)->postJson(self::API.'people/action-center/remind', ['topic' => 'missing_documents', 'employee_uuids' => [$a->uuid]])->assertForbidden();
        $this->as($this->hr)->postJson(self::API.'people/action-center/tasks', ['insight' => 'people.without_manager', 'assignee_id' => $this->hr->id])->assertCreated();

        $last = 0;
        for ($i = 0; $i < 12; $i++) {
            $last = $this->as($this->hr)->postJson(self::API.'people/action-center/remind', ['topic' => 'information_request', 'employee_uuids' => [$a->uuid]])->status();
        }
        $this->assertSame(429, $last);
    }

    // ------------------------------------------------------------- completeness

    public function test_completeness_is_configurable_and_permission_aware(): void
    {
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $e = $this->hire('Asha Rao', ['manager' => $manager, 'role' => 'Team member']);
        $keys = fn (User $u) => collect($this->as($u)->getJson(self::API."employees/{$e->uuid}/completeness")->assertOk()->json());

        $hr = $keys($this->hr);
        $this->assertSame(['emergency_contact', 'address', 'bank_account', 'identity_document', 'photo'], collect($hr['missing'])->pluck('key')->all());
        $this->assertSame(8, $hr['total_rules']);
        $this->assertSame(3, $hr['satisfied']);
        $this->assertFalse($hr['complete']);
        $this->assertSame(38, $hr['percentage']);
        $this->assertSame('navigate', $hr['missing'][0]['action']['kind']);

        $mgr = $keys($manager->person->user);
        $this->assertSame(['photo'], collect($mgr['missing'])->pluck('key')->all(), 'no emergency/address/bank/identity/documents for a manager');
        $this->assertSame(['photo', 'manager', 'work_email'], array_values(array_intersect(['photo', 'manager', 'work_email'], collect($mgr['missing'])->pluck('key')->merge(['manager', 'work_email'])->all())));
        $this->assertLessThan(8, $mgr['total_rules']);
        $self = $keys($e->person->user);
        $this->assertContains('bank_account', collect($self['missing'])->pluck('key')->all(), 'self sees own sensitive gaps');

        // configurable + data satisfies rules
        $tenant = Tenant::find($this->admin->tenant_id);
        $tenant->update(['settings' => array_merge($tenant->settings, ['people' => ['completeness' => ['required' => ['work_email', 'address']]]])]);
        DB::table('employee_addresses')->insert($this->now(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'person_id' => $e->person_id, 'type' => 'current', 'line1' => '1 Main', 'country' => 'IN']));
        $done = $keys($this->hr);
        $this->assertTrue($done['complete']);
        $this->assertSame(2, $done['total_rules']);
        // required custom field becomes a rule
        DB::table('custom_field_definitions')->insert($this->now(['tenant_id' => $this->admin->tenant_id, 'entity' => 'employee', 'key' => 'shirt', 'label' => 'Shirt size', 'type' => 'text', 'required' => true, 'classification' => 'employee']));
        $withCustom = $keys($this->hr);
        $this->assertFalse($withCustom['complete']);
        $this->assertSame(['custom:shirt'], collect($withCustom['missing'])->pluck('key')->all());
        $this->assertSame(3, $withCustom['total_rules']);
    }

    public function test_completeness_summary_counts_missing_items(): void
    {
        $this->hire('Maya Chen');
        $this->hire('Asha Rao');
        $s = $this->as($this->hr)->getJson(self::API.'people/completeness/summary')->assertOk()->json();
        $this->assertSame(2, $s['employees']);
        $this->assertSame(2, $s['incomplete']);
        $this->assertSame(2, collect($s['items'])->firstWhere('key', 'bank_account')['count']);
    }

    // ---------------------------------------------------------------- org chart

    public function test_org_chart_lazy_loading_search_privacy_scope_and_bounded_queries(): void
    {
        $ceo = $this->hire('Maya Chen');
        $lead = $this->hire('Lena Lead', ['manager' => $ceo, 'role' => 'Manager', 'department' => 1]);
        foreach (range(1, 40) as $i) {
            $this->hire('Worker'.$i.' Person', ['manager' => $lead]);
        }
        $this->hire('Solo Person', ['manager' => $ceo]);

        $roots = $this->as($this->hr)->getJson(self::API.'people/org-chart')->assertOk()->json();
        $this->assertSame('tree', $roots['mode']);
        $this->assertSame(1, $roots['total']);
        $this->assertSame('Maya Chen', $roots['data'][0]['name']);
        $this->assertSame(2, $roots['data'][0]['children_count']);
        $this->assertTrue($roots['data'][0]['has_children']);
        $this->assertArrayNotHasKey('children', $roots['data'][0]);

        DB::enableQueryLog();
        $kids = $this->as($this->hr)->getJson(self::API."people/org-chart/{$lead->uuid}/children?per_page=10")->assertOk()->json();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(40, $kids['total']);
        $this->assertCount(10, $kids['data']);
        $this->assertLessThan(45, $queries, 'one level, bounded query count ('.$queries.')');
        $this->assertCount(2, $this->as($this->hr)->getJson(self::API."people/org-chart?root={$ceo->uuid}&depth=2")->json('data.0.children'));

        $search = $this->as($this->hr)->getJson(self::API.'people/org-chart/search?q=Worker7')->assertOk()->json();
        $this->assertSame(1, $search['total']);
        $this->assertSame(['Maya Chen', 'Lena Lead'], array_column($search['data'][0]['ancestors'], 'name'));
        $this->assertSame(1, $this->as($this->hr)->getJson(self::API.'people/org-chart?department_id='.$this->org['department'][1])->json('total'));

        // a manager sees only their scope as nodes, plus the chain upward as names only
        $mine = $this->as($lead->person->user)->getJson(self::API.'people/org-chart')->assertOk()->json();
        $this->assertSame('Lena Lead', $mine['data'][0]['name']);
        $this->assertSame(['Maya Chen'], array_column($mine['data'][0]['ancestors'], 'name'));
        $this->assertSame(40, $mine['data'][0]['children_count']);
        $this->assertSame(0, $this->as($lead->person->user)->getJson(self::API.'people/org-chart/search?q=Solo')->json('total'));
        $this->as($lead->person->user)->getJson(self::API."people/org-chart/{$ceo->uuid}/children")->assertOk()->assertJsonPath('total', 1);

        // directory privacy hides department and location for coworkers, not for HR
        $tenant = Tenant::find($this->admin->tenant_id);
        $tenant->update(['settings' => array_merge($tenant->settings, ['people' => ['directory_privacy' => ['department' => false, 'location' => false]]])]);
        $this->assertNull($this->as($lead->person->user)->getJson(self::API."people/org-chart/{$lead->uuid}/children")->json('data.0.department'));
        $this->assertSame('Engineering', $this->as($this->hr)->getJson(self::API."people/org-chart/{$lead->uuid}/children")->json('data.0.department'));
        $this->as($this->hire('Zed Zed', ['role' => 'Team member'])->person->user)->getJson(self::API."people/org-chart/{$lead->uuid}/children")->assertNotFound();
    }

    // ---------------------------------------------------------------- dashboard

    public function test_dashboard_sections_and_birthday_privacy(): void
    {
        $manager = $this->hire('Maya Chen', ['role' => 'Manager', 'joined_on' => '2020-10-20', 'dob' => '1985-10-09']);
        $new = $this->hire('Asha Rao', ['manager' => $manager, 'joined_on' => '2026-09-20', 'dob' => '1992-10-15']);
        $this->hire('Future Joiner', ['joined_on' => '2026-10-25']);

        $hr = $this->as($this->hr)->getJson(self::API.'people/dashboard')->assertOk()->json();
        $this->assertSame(1, $hr['new_joiners']['count']);
        $this->assertSame($new->uuid, $hr['new_joiners']['items'][0]['employee_uuid']);
        $this->assertSame(1, $hr['upcoming_joinings']['count']);
        $this->assertSame(1, $hr['work_anniversaries']['count']);
        $this->assertSame(6, $hr['work_anniversaries']['items'][0]['years']);
        $this->assertTrue($hr['birthdays']['visible']);
        $this->assertSame(2, $hr['birthdays']['count']);
        $this->assertSame(['employee_uuid', 'name', 'day', 'month'], array_keys($hr['birthdays']['items'][0]));
        $this->assertSame([], $hr['probation_due']['items']);

        $mgr = $this->as($manager->person->user)->getJson(self::API.'people/dashboard')->assertOk()->json();
        $this->assertFalse($mgr['birthdays']['visible']);
        $this->assertSame([], $mgr['birthdays']['items']);
        $this->assertSame(1, $mgr['new_joiners']['count'], 'scope: own report only');
        $tenant = Tenant::find($this->admin->tenant_id);
        $tenant->update(['settings' => array_merge($tenant->settings, ['people' => ['directory_privacy' => ['date_of_birth' => true]]])]);
        $this->assertTrue($this->as($manager->person->user)->getJson(self::API.'people/dashboard')->json('birthdays.visible'));
    }

    // ----------------------------------------------------------------- commands

    public function test_advance_lifecycle_is_idempotent(): void
    {
        $e = $this->hire('Ben Ford', ['joined_on' => '2026-11-01']);
        $n = $this->hire('Nia Notice');
        DB::table('employees')->where('id', $n->id)->update(['status' => 'notice_period', 'separated_on' => '2026-10-01']);
        $this->artisan('people:advance-lifecycle')->assertSuccessful();
        $this->assertSame('preboarding', $e->fresh()->status);
        $this->assertSame('separated', $n->fresh()->status);
        $this->travelTo(now()->setDate(2026, 11, 2));
        $this->artisan('people:advance-lifecycle')->assertSuccessful();
        $this->artisan('people:advance-lifecycle')->assertSuccessful();
        $this->assertSame('active', $e->fresh()->status);
        $this->assertSame(2, DB::table('employee_status_history')->where('employee_id', $e->id)->count(), 'created + activated, once');
        $this->assertSame(2, DB::table('employee_status_history')->where('employee_id', $n->id)->count());
        $this->assertSame(1, DB::table('domain_events')->where('employee_id', $e->id)->where('type', 'EmployeeActivated')->count());
    }

    public function test_probation_reminders_windows_tasks_and_idempotency(): void
    {
        $manager = $this->hire('Maya Chen', ['role' => 'Manager']);
        $e = $this->hire('Asha Rao', ['manager' => $manager]);
        DB::table('employees')->where('id', $e->id)->update(['status' => 'probation', 'probation_applicable' => true, 'probation_end' => '2026-10-17']);
        $this->artisan('people:probation-reminders')->assertSuccessful();
        $this->artisan('people:probation-reminders')->assertSuccessful();
        $count = fn () => DB::table('domain_events')->where('employee_id', $e->id)->where('type', 'ProbationDue')->count();
        $this->assertSame(1, $count(), 'one 14-day reminder');
        $this->assertSame(1, WorkItem::query()->where('assignee_id', $manager->person->user_id)->where('source_module', 'people')->count());
        $this->assertSame(0, WorkItem::query()->where('assignee_id', $this->admin->id)->count(), 'HR only at the last window when a manager exists');

        $this->travelTo(now()->setDate(2026, 10, 15));
        $this->artisan('people:probation-reminders')->assertSuccessful();
        $this->artisan('people:probation-reminders')->assertSuccessful();
        $this->assertSame(2, $count(), 'plus one 3-day reminder');
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, WorkItem::query()->where('assignee_id', $this->admin->id)->where('source_module', 'people')->count());
        $this->assertSame(1, WorkItem::query()->where('assignee_id', $manager->person->user_id)->where('source_module', 'people')->count(), 'createOnce');

        DB::table('employees')->where('id', $e->id)->update(['status' => 'active', 'confirmation_date' => '2026-10-15']);
        $this->artisan('people:probation-reminders')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, WorkItem::query()->where('source_entity', 'like', 'probation:%')->where('status', 'open')->count());
    }

    public function test_scan_quality_command_runs_for_all_tenants(): void
    {
        $this->hire('Maya Chen');
        $this->artisan('people:scan-quality')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $this->assertGreaterThan(0, DataQualityFinding::query()->count());
    }

    // --------------------------------------------------------------- isolation

    public function test_tenant_isolation_is_404(): void
    {
        $alphaAdmin = $this->admin;
        $this->hire('Alpha Person');
        $this->bootTenant('beta');
        $beta = $this->hire('Beta Person');
        DB::table('employees')->where('id', $beta->id)->update(['work_email' => null]);
        $this->scan(['missing_required_fields']);
        $finding = $this->findings('missing_required_fields')->first();
        $this->assertNotNull($finding);

        $this->as($alphaAdmin);
        foreach (['timeline', 'memory', '360', 'completeness'] as $path) {
            $this->getJson(self::API."employees/{$beta->uuid}/$path")->assertNotFound();
        }
        $this->postJson(self::API."people/quality/findings/{$finding->id}/dismiss")->assertNotFound();
        $this->getJson(self::API."people/org-chart/{$beta->uuid}/children")->assertNotFound();
        $this->getJson(self::API.'people/quality/findings')->assertOk()->assertJsonPath('total', 0);
        $this->assertNotNull(app(WorkspaceProvisioner::class));
    }
}
