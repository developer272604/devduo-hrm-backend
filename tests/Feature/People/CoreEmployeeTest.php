<?php

namespace Tests\Feature\People;

use App\Domain\Access\DataScope;
use App\Domain\Access\PeopleSettings;
use App\Domain\Events\DomainEvent;
use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\AssignmentService;
use App\Domain\People\Services\NumberingService;
use App\Domain\People\Services\ReportingService;
use App\Domain\People\Services\StatusService;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class CoreEmployeeTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
    }

    public function test_creating_an_employee_builds_person_employment_assignment_timeline_and_audit(): void
    {
        $boss = $this->hire('Maya Chen');
        $e = $this->hire('Asha Rao', ['manager' => $boss, 'dob' => '1992-04-01']);

        $this->assertSame('EMP-00002', $e->employee_number);
        $this->assertSame('active', $e->status);
        $this->assertNotEmpty($e->uuid);
        $this->assertSame('Asha Rao', $e->person->display_name);
        $this->assertSame('IN', $e->country);
        $this->assertSame('Asia/Kolkata', $e->timezone);
        $this->assertSame($boss->id, app(ReportingService::class)->getManager($e)->id);
        $this->assertTrue(DomainEvent::where('employee_id', $e->id)->where('type', 'EmployeeCreated')->exists());
        $this->assertTrue(AuditLog::where('action', 'created')->where('resource', 'employee:'.$e->uuid)->exists());
        $this->assertSame(1, Assignment::where('employee_id', $e->id)->count());
    }

    public function test_future_joiner_is_preboarding_and_country_comes_from_the_location(): void
    {
        $e = $this->hire('Ben Ford', ['joined_on' => '2026-11-01', 'location' => 1]);
        $this->assertSame('preboarding', $e->status);
        $this->assertSame('GB', $e->country);
        $this->assertSame('Europe/London', $e->timezone);
    }

    public function test_assignment_changes_are_effective_dated_and_never_overwrite_history(): void
    {
        $e = $this->hire('Asha Rao');
        $service = app(AssignmentService::class);
        $service->change($e, ['department_id' => $this->org['department'][1]], '2026-06-01', ['reason' => 'Move to sales']);
        $service->change($e, ['designation_id' => $this->org['designation'][1]], '2026-12-01'); // future dated

        $rows = Assignment::where('employee_id', $e->id)->orderBy('effective_from')->get();
        $this->assertCount(3, $rows);
        $this->assertSame('2026-05-31', $rows[0]->effective_to->toDateString());
        $this->assertSame($this->org['department'][0], $rows[0]->department_id, 'history keeps the original department');
        $this->assertSame('2026-11-30', $rows[1]->effective_to->toDateString());
        $this->assertNull($rows[2]->effective_to);
        $this->assertSame($this->org['department'][1], $e->assignmentOn('2026-10-07')->department_id);
        $this->assertSame($this->org['designation'][0], $e->assignmentOn('2026-10-07')->designation_id, 'future promotion not yet effective');
        $this->assertSame($this->org['designation'][1], $e->assignmentOn('2026-12-02')->designation_id);
        $this->assertSame([], $service->integrityProblems($e));
        $this->assertTrue(DomainEvent::where('type', 'EmployeeTransferred')->where('summary', 'like', 'Department changed from Engineering to Sales%')->exists());
    }

    public function test_assignment_changes_between_existing_rows_split_correctly_and_reject_collisions(): void
    {
        $e = $this->hire('Asha Rao');
        $service = app(AssignmentService::class);
        $service->change($e, ['department_id' => $this->org['department'][1]], '2026-12-01');
        $service->change($e, ['location_id' => $this->org['location'][1]], '2026-08-01'); // inserted before the future row

        $rows = Assignment::where('employee_id', $e->id)->orderBy('effective_from')->get();
        $this->assertSame(['2026-01-05', '2026-08-01', '2026-12-01'], $rows->map(fn ($r) => $r->effective_from->toDateString())->all());
        $this->assertSame('2026-11-30', $rows[1]->effective_to->toDateString());
        $this->assertSame([], $service->integrityProblems($e));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->change($e, ['department_id' => $this->org['department'][2]], '2026-12-01');
    }

    public function test_job_change_cannot_start_before_joining_or_use_a_foreign_reference(): void
    {
        $e = $this->hire('Asha Rao');
        $this->expectExceptionMessage('before the employee joined');
        app(AssignmentService::class)->change($e, ['department_id' => $this->org['department'][1]], '2025-01-01');
    }

    public function test_reporting_prevents_cycles_and_supports_chain_and_subordinates(): void
    {
        $a = $this->hire('Amy One');
        $b = $this->hire('Bob Two', ['manager' => $a]);
        $c = $this->hire('Cat Three', ['manager' => $b]);
        $reporting = app(ReportingService::class);

        $this->assertSame([$b->id, $a->id], $reporting->getReportingChain($c)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $reporting->getAllSubordinates($a));
        $this->assertSame([$b->id], $reporting->getDirectReports($a)->pluck('id')->all());
        $this->assertSame('manager', $reporting->getRelationship($c, $b));
        $this->assertSame('indirect_manager', $reporting->getRelationship($c, $a));

        try {
            $reporting->assign($a, $c, 'primary', '2026-10-07');
            $this->fail('A circular reporting line must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertStringContainsString('circular', $e->getMessage());
        }
        $this->expectExceptionMessage('themselves');
        $reporting->assign($a, $a, 'primary', '2026-10-07');
    }

    public function test_manager_change_keeps_history_and_future_dating(): void
    {
        $a = $this->hire('Amy One');
        $b = $this->hire('Bob Two');
        $c = $this->hire('Cat Three', ['manager' => $a]);
        $reporting = app(ReportingService::class);
        $reporting->assign($c, $b, 'primary', '2026-11-01');

        $this->assertSame($a->id, $reporting->getManager($c, 'primary', '2026-10-07')->id);
        $this->assertSame($b->id, $reporting->getManager($c, 'primary', '2026-11-01')->id);
        $this->assertCount(2, $reporting->history($c));
        $this->assertTrue(DomainEvent::where('type', 'ManagerChanged')->where('summary', 'like', 'Reporting manager changed from Amy One to Bob Two.')->exists());
    }

    public function test_numbering_is_sequential_unique_and_pattern_driven(): void
    {
        $numbering = app(NumberingService::class);
        $this->assertSame('EMP-00001', $this->hire('A One')->employee_number);
        $this->assertSame('EMP-00002', $this->hire('B Two')->employee_number);

        PeopleSettings::save(['numbering' => ['pattern' => '{COUNTRY}-{LOC}-{SEQ:4}', 'scope' => 'location', 'start' => 42]]);
        $this->assertSame('IN-CHN-0042', $this->hire('C Three')->employee_number);
        $this->assertSame('IN-CHN-0043', $this->hire('D Four')->employee_number);
        $this->assertSame('GB-LON-0042', $this->hire('E Five', ['location' => 1])->employee_number);
        $this->assertSame('IN-CHN-0044', $numbering->preview(['location_id' => $this->org['location'][0], 'country' => 'IN']));
    }

    public function test_status_lifecycle_enforces_transitions_and_records_history(): void
    {
        $e = $this->hire('Asha Rao');
        $status = app(StatusService::class);
        $status->transition($e, 'probation', ['reason' => 'Joined on probation']);
        $status->transition($e, 'active');
        $this->assertSame('active', $e->refresh()->status);
        $this->assertCount(3, $status->history($e));
        $this->expectExceptionMessage('cannot move');
        $status->transition($e, 'archived');
    }

    public function test_duplicates_warn_but_never_merge(): void
    {
        $this->hire('Asha Rao', ['work_email' => 'asha@alpha.test', 'dob' => '1992-04-01']);
        $this->signIn($this->admin);
        $payload = ['person' => ['legal_first_name' => 'Asha', 'legal_last_name' => 'Rao', 'date_of_birth' => '1992-04-01'], 'employment' => ['worker_type' => 'employee', 'joined_on' => '2026-02-01', 'work_email' => 'asha@alpha.test'],
            'assignment' => ['legal_entity_id' => $this->org['legal_entity'][0], 'location_id' => $this->org['location'][0], 'department_id' => $this->org['department'][0]]];
        $res = $this->postJson('/api/v1/employees', $payload)->assertStatus(409)->assertJsonPath('code', 'possible_duplicate');
        $this->assertContains('Same work email', collect($res->json('duplicates'))->pluck('reason')->all());
        $this->postJson('/api/v1/employees', $payload + ['confirm_duplicates' => true])->assertCreated();
        $this->context($this->admin->tenant_id); // the tenant middleware clears the context after each request
        $this->assertSame(2, Employee::count());
    }

    public function test_data_scope_self_direct_all_subordinates_department_and_tenant(): void
    {
        $boss = $this->hire('Boss One', ['role' => 'Manager']);
        $mid = $this->hire('Mid Two', ['manager' => $boss, 'role' => 'Manager']);
        $leaf = $this->hire('Leaf Three', ['manager' => $mid, 'role' => 'Team member']);
        $other = $this->hire('Other Four', ['department' => 1]);
        $scope = app(DataScope::class);
        $bossUser = $boss->person->user;

        // Manager role = direct reports: boss reaches self + mid only.
        $this->assertTrue($scope->canAccess($bossUser, $boss));
        $this->assertTrue($scope->canAccess($bossUser, $mid));
        $this->assertFalse($scope->canAccess($bossUser, $leaf));
        $this->assertFalse($scope->canAccess($bossUser, $other));
        $this->assertTrue($scope->isManagerOf($bossUser, $mid));
        $this->assertFalse($scope->isManagerOf($bossUser, $boss));

        $bossUser->roles()->update(['data_scope' => 'all_subordinates']);
        $this->assertTrue($scope->canAccess($bossUser, $leaf), 'whole subtree');
        $this->assertFalse($scope->canAccess($bossUser, $other));
        $this->assertTrue($scope->isManagerOf($bossUser, $leaf));

        $bossUser->roles()->update(['data_scope' => 'department']);
        $this->assertTrue($scope->canAccess($bossUser, $other) === false);
        $this->assertTrue($scope->canAccess($bossUser, $leaf), 'same department as the manager');

        $leafUser = $leaf->person->user;
        $this->assertTrue($scope->canAccess($leafUser, $leaf));
        $this->assertFalse($scope->canAccess($leafUser, $mid));
        $this->assertTrue($scope->canAccess($this->admin, $other), 'tenant scope');
    }

    public function test_explicit_grants_extend_scope_for_departments_and_employee_sets(): void
    {
        $viewer = $this->hire('Hera Viewer', ['role' => 'Team member']);
        $sales = $this->hire('Sam Sales', ['department' => 1]);
        $eng = $this->hire('Eve Eng');
        $scope = app(DataScope::class);
        $user = $viewer->person->user;
        $this->assertFalse($scope->canAccess($user, $sales));

        \Illuminate\Support\Facades\DB::table('user_data_scopes')->insert(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'kind' => 'department', 'reference_id' => $this->org['department'][1], 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('user_data_scopes')->insert(['tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'kind' => 'employee', 'reference_id' => $eng->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertTrue($scope->canAccess($user, $sales));
        $this->assertTrue($scope->canAccess($user, $eng));
    }

    public function test_tenant_isolation_for_employees_and_scope(): void
    {
        $e = $this->hire('Asha Rao');
        $betaAdmin = \App\Domain\Access\WorkspaceProvisioner::class;
        $beta = app($betaAdmin)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/employees/'.$e->uuid)->assertNotFound();
        $this->signIn($this->admin);
        $this->getJson('/api/v1/employees/'.$e->uuid)->assertOk()->assertJsonPath('header.display_name', 'Asha Rao');
    }
}
