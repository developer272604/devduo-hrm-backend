<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Models\Roster;
use App\Domain\Attendance\Models\RosterEntry;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Scheduling\ScheduleAssignmentService;
use App\Domain\Attendance\Scheduling\ShiftService;
use App\Domain\Events\DomainEvent;
use App\Domain\People\Models\Employee;
use App\Models\InAppNotification;
use App\Models\Role;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class SchedulingRosterTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
    }

    private function shift(string $code, string $start = '09:00', string $end = '18:00', array $extra = []): Shift
    {
        return app(ShiftService::class)->create($extra + ['name' => $code, 'code' => $code, 'timezone' => 'Asia/Kolkata', 'start_time' => $start, 'end_time' => $end]);
    }

    private function roster(string $from = '2026-11-02', string $to = '2026-11-15'): Roster
    {
        return app(\App\Domain\Attendance\Scheduling\RosterService::class)->create(['name' => 'Nov', 'start_date' => $from, 'end_date' => $to], $this->admin);
    }

    private function svc(): \App\Domain\Attendance\Scheduling\RosterService
    {
        return app(\App\Domain\Attendance\Scheduling\RosterService::class);
    }

    private function set(Roster $r, Employee $e, string $date, ?Shift $s, ?string $reason = null, string $type = 'shift')
    {
        return $this->svc()->setEntry($r, $e, $date, $type, $s, $reason, $this->admin);
    }

    private function conflictCodes(array $conflicts): array
    {
        return array_values(array_unique(array_column($conflicts, 'code')));
    }

    public function test_draft_publish_change_republish_keeps_history_and_notifies_once(): void
    {
        $user = $this->userWithRole('Team member', 'asha@alpha.test', 'Asha');
        $e = $this->hire('Asha Rao');
        \DB::table('people')->where('id', $e->person_id)->update(['user_id' => $user->id]);
        $a = $this->shift('A');
        $b = $this->shift('B', '14:00', '22:00');
        $r = $this->roster();
        foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $d) {
            $this->set($r, $e, $d, $a);
        }
        $this->assertSame(0, RosterEntry::where('status', 'published')->count());
        $res = $this->svc()->publish($r, $this->admin, true);
        $this->assertSame(1, $res['version']);
        $this->assertSame(3, RosterEntry::where('status', 'published')->count());
        $this->assertSame(1, InAppNotification::where('user_id', $user->id)->count()); // ONE notification for three days
        $this->assertSame(1, DomainEvent::where('type', 'RosterPublished')->count());
        $this->assertSame(1, DomainEvent::where('type', 'ShiftAssigned')->where('employee_id', $e->id)->count());

        // changing a published day needs a reason
        try {
            $this->set($r, $e, '2026-11-03', $b);
            $this->fail('reason required');
        } catch (\Illuminate\Validation\ValidationException $ex) {
            $this->assertArrayHasKey('reason', $ex->errors());
        }
        $this->set($r, $e, '2026-11-03', $b, 'Cover for sick colleague');
        $draft = RosterEntry::where('status', 'draft')->first();
        $this->assertNotNull($draft->previous_entry_id);
        $this->assertSame('Cover for sick colleague', $draft->change_reason);
        $this->assertSame($this->admin->id, $draft->changed_by);
        // published row untouched until republish; resolver still sees A
        $this->assertSame('A', app(\App\Domain\Attendance\Contracts\ScheduleResolver::class)->resolve($e->id, '2026-11-03')->shiftCode);
        $this->assertCount(1, $this->svc()->changedSince($r));

        $this->svc()->publish($r, $this->admin, true);
        $rows = RosterEntry::where('work_date', '2026-11-03')->orderBy('id')->get();
        $this->assertSame(['superseded', 'published'], $rows->pluck('status')->all());
        $this->assertSame($rows[0]->id, $rows[1]->previous_entry_id);
        $this->assertSame('B', app(\App\Domain\Attendance\Contracts\ScheduleResolver::class)->resolve($e->id, '2026-11-03')->shiftCode);
        $this->assertTrue(app(\App\Domain\Attendance\Contracts\ScheduleResolver::class)->resolve($e->id, '2026-11-03')->rosterEntryId === $rows[1]->id);
        $hist = $this->svc()->history($r, $e, '2026-11-03');
        $this->assertCount(2, $hist);
        $this->assertSame('A', $hist[1]['previous_shift']);
        $this->assertSame('B', $hist[1]['new_shift']);
        $this->assertSame(1, DomainEvent::where('type', 'ShiftChanged')->where('employee_id', $e->id)->count());
        $this->assertSame(2, InAppNotification::where('user_id', $user->id)->count());

        // unpublish keeps history and can be republished
        $this->svc()->unpublish($r, $this->admin, 'Rework');
        $this->assertSame(0, RosterEntry::where('status', 'published')->count());
        $this->assertSame(3, RosterEntry::where('status', 'draft')->count());
        $this->svc()->publish($r, $this->admin, true);
        $this->assertSame(3, RosterEntry::where('status', 'published')->count());
    }

    public function test_conflicts_overlap_rest_employment_location_and_layers(): void
    {
        $e = $this->hire('Ben Ford', ['location' => 1, 'joined_on' => '2026-11-04']); // London, GB pack: min rest 11h
        $late = $this->shift('LATE', '14:00', '23:00', ['timezone' => 'Europe/London']);
        $early = $this->shift('EARLY', '07:00', '16:00', ['timezone' => 'Europe/London']);
        $over = $this->shift('OVER', '20:00', '06:00', ['timezone' => 'Europe/London']);
        $chn = $this->shift('CHN', '09:00', '18:00', ['rules' => ['allowed_location_ids' => [$this->org['location'][0]]]]);
        $r = $this->roster();
        $this->set($r, $e, '2026-11-09', $late);
        $this->set($r, $e, '2026-11-10', $early);   // 8h rest < 11h (GB)
        $this->set($r, $e, '2026-11-11', $over);
        $this->set($r, $e, '2026-11-12', $early);   // overlaps the night shift ending 06:00? no: 06:00 end, 07:00 start => 1h rest
        $this->set($r, $e, '2026-11-13', $chn);     // location invalid
        $this->set($r, $e, '2026-11-03', $early);   // before joining
        $codes = $this->conflictCodes($this->svc()->validate($r));
        foreach (['insufficient_rest', 'invalid_location', 'outside_employment'] as $c) {
            $this->assertContains($c, $codes);
        }
        $first = collect($this->svc()->validate($r))->firstWhere('code', 'insufficient_rest');
        $this->assertSame('country', $first['layer']);
        $this->assertSame('flag', $first['level']);

        // tenant layer: override GB minimum rest to 6h -> the rest warning disappears; enforcement block marks level=block
        app(\App\Domain\Attendance\Scheduling\ComplianceRules::class)->save(['countries' => ['GB' => ['rules' => ['min_rest_minutes' => 360]]]]);
        $this->assertNotContains('insufficient_rest', array_column(array_filter($this->svc()->validate($r), fn ($c) => $c['date'] === '2026-11-10'), 'code'));
        app(\App\Domain\Attendance\Scheduling\ComplianceRules::class)->save(['enforcement' => 'block']);
        $this->assertTrue(\App\Domain\Attendance\Scheduling\ConflictDetector::hasBlocking($this->svc()->validate($r)));
        try {
            $this->svc()->publish($r, $this->admin, true);
            $this->fail('blocked');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $ex) {
            $this->assertSame('roster_conflicts', $ex->getResponse()->getData(true)['code']);
        }
    }

    public function test_publish_requires_acknowledging_warnings_and_engine_has_no_country_hardcoding(): void
    {
        $e = $this->hire('Asha Rao');
        $long = $this->shift('LONG', '06:00', '20:00');   // 14h > India 9h daily
        $r = $this->roster();
        $this->set($r, $e, '2026-11-03', $long);
        try {
            $this->svc()->publish($r, $this->admin, false);
            $this->fail('warn');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $ex) {
            $this->assertSame(409, $ex->getResponse()->getStatusCode());
            $this->assertSame('roster_warnings', $ex->getResponse()->getData(true)['code']);
        }
        $this->svc()->publish($r, $this->admin, true);
        $engine = file_get_contents(app_path('Domain/Attendance/Scheduling/ConflictDetector.php')).file_get_contents(app_path('Domain/Attendance/Scheduling/ComplianceRules.php'));
        $this->assertDoesNotMatchRegularExpression("/'(IN|US|GB|AE|DE|SG|AU)'/", $engine);
    }

    public function test_consecutive_night_limit_flags_only_the_first_night_over_the_tenant_limit(): void
    {
        $employee = $this->hire('Ben Ford', ['location' => 1]); // GB compliance pack
        $night = $this->shift('NIGHT', '22:00', '06:00', ['timezone' => 'Europe/London']);
        $roster = $this->roster();
        foreach (['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'] as $date) {
            $this->set($roster, $employee, $date, $night);
        }
        app(\App\Domain\Attendance\Scheduling\ComplianceRules::class)->save([
            'countries' => ['GB' => ['rules' => ['max_consecutive_nights' => 2]]],
        ]);

        $conflicts = array_values(array_filter($this->svc()->validate($roster), fn ($conflict) => $conflict['code'] === 'max_consecutive_nights'));
        $this->assertCount(1, $conflicts);
        $this->assertSame('2026-11-11', $conflicts[0]['date']);
        $this->assertSame('tenant', $conflicts[0]['layer']);
        $this->assertSame('flag', $conflicts[0]['level']);
    }

    public function test_bulk_assign_preview_copy_and_locks(): void
    {
        $e1 = $this->hire('Asha Rao');
        $e2 = $this->hire('Ben Lee');
        $a = $this->shift('A');
        $r = $this->roster();
        $pre = $this->svc()->bulkAssign($r, [$e1, $e2], '2026-11-02', '2026-11-06', null, 'shift', $a, null, $this->admin, true, false);
        $this->assertSame(10, $pre['cells']);
        $this->assertSame(0, RosterEntry::count());
        $res = $this->svc()->bulkAssign($r, [$e1, $e2], '2026-11-02', '2026-11-06', [1, 2, 3, 4, 5], 'shift', $a, null, $this->admin, false, true);
        $this->assertSame(10, $res['applied']);
        $copy = $this->svc()->copy($r, [$e1, $e2], '2026-11-02', '2026-11-09', 5, $this->admin);
        $this->assertSame(10, $copy['copied']);
        $this->assertSame(20, RosterEntry::where('status', 'draft')->count());

        app()->instance(LockGuard::class, new class implements LockGuard
        {
            public function isLocked(int $employeeId, string $date): bool
            {
                return $date <= '2026-09-30';
            }

            public function assertUnlocked(int $employeeId, string $date): void
            {
                abort_if($this->isLocked($employeeId, $date), 423, "Attendance for $date is locked.");
            }

            public function lockFor(int $employeeId, string $date): ?array
            {
                return null;
            }
        });
        $past = $this->roster('2026-09-01', '2026-09-30');
        try {
            $this->set($past, $e1, '2026-09-15', $a);
            $this->fail('locked');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $ex) {
            $this->assertSame(423, $ex->getStatusCode());
        }
    }

    public function test_publish_requests_recalculation_for_past_dates(): void
    {
        $e = $this->hire('Asha Rao');
        $a = $this->shift('A');
        $calls = [];
        app()->instance(AttendanceRecalculator::class, new class($calls) implements AttendanceRecalculator
        {
            public function __construct(public array &$calls) {}

            public function recalculateDay(int $employeeId, string $date, string $trigger, ?int $requestedBy = null): ?int
            {
                return null;
            }

            public function recalculate(array $scope, string $trigger, ?int $requestedBy = null, ?string $dedupeKey = null): string
            {
                $this->calls[] = $scope;

                return 'run';
            }
        });
        $r = $this->roster('2026-10-01', '2026-10-20');
        $this->set($r, $e, '2026-10-05', $a);
        $this->set($r, $e, '2026-10-15', $a); // future: not recalculated
        $this->svc()->publish($r, $this->admin, true);
        $this->assertCount(1, $calls);
        $this->assertSame('2026-10-05', $calls[0]['from']);
        $this->assertSame('2026-10-07', $calls[0]['to']);
    }

    public function test_api_data_scope_permissions_and_tenant_isolation(): void
    {
        $boss = $this->hire('Maya Chen');
        $mine = $this->hire('Asha Rao', ['manager' => $boss]);
        $other = $this->hire('Zed Other');
        $role = Role::create(['name' => 'Scheduler', 'description' => 'x', 'data_scope' => 'direct_reports']);
        $ids = \App\Models\Permission::whereIn('key', ['attendance.view', 'attendance.roster.manage', 'attendance.roster.publish', 'attendance.team.view'])->pluck('id');
        $role->permissions()->sync($ids);
        $user = $this->userWithRole('Scheduler', 'maya@alpha.test', 'Maya');
        \DB::table('people')->where('id', $boss->person_id)->update(['user_id' => $user->id]);
        $a = $this->shift('A');

        $this->signIn($this->admin);
        $uuid = $this->postJson('/api/v1/rosters', ['name' => 'Nov', 'start_date' => '2026-11-02', 'end_date' => '2026-11-15'])->assertCreated()->json('data.uuid');
        $this->signIn($user);
        $this->postJson("/api/v1/rosters/$uuid/entries", ['employee_uuid' => $mine->uuid, 'date' => '2026-11-03', 'entry_type' => 'shift', 'shift_uuid' => $a->uuid])->assertOk();
        $this->postJson("/api/v1/rosters/$uuid/entries", ['employee_uuid' => $other->uuid, 'date' => '2026-11-03', 'entry_type' => 'shift', 'shift_uuid' => $a->uuid])->assertNotFound();
        $this->postJson("/api/v1/rosters/$uuid/bulk-assign", ['employee_uuids' => [$mine->uuid, $other->uuid], 'from' => '2026-11-03', 'to' => '2026-11-03', 'shift_uuid' => $a->uuid])->assertNotFound();
        $grid = $this->getJson("/api/v1/rosters/$uuid/grid?view=week&date=2026-11-03&employee_uuids[]={$mine->uuid}&employee_uuids[]={$other->uuid}")->assertOk()->json();
        $this->assertSame([$mine->uuid], array_column($grid['data'], 'employee_uuid'));
        $this->assertSame('draft', $grid['data'][0]['cells']['2026-11-03']['state']);

        // a plain team member cannot manage rosters
        $this->context($this->admin->tenant_id);
        $member = $this->userWithRole('Team member', 'm@alpha.test', 'M');
        $this->signIn($member);
        $this->getJson('/api/v1/rosters')->assertForbidden();

        // another tenant sees 404
        $this->bootSecondTenant();
        $this->getJson("/api/v1/rosters/$uuid")->assertNotFound();
        $this->getJson("/api/v1/shifts/{$a->uuid}")->assertNotFound();
    }

    private function bootSecondTenant(): void
    {
        $u = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($u);
    }

    public function test_roster_grid_query_count_does_not_grow_with_employees(): void
    {
        $a = $this->shift('A');
        $r = $this->roster('2026-11-02', '2026-11-08');
        $es = [];
        for ($i = 0; $i < 12; $i++) {
            $es[] = $this->hire('Emp'.$i.' Test');
        }
        $this->signIn($this->admin);
        $count = function (int $n) use ($r, $es) {
            $uuids = array_map(fn ($e) => $e->uuid, array_slice($es, 0, $n));
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->getJson('/api/v1/rosters/'.$r->uuid.'/grid?from=2026-11-02&to=2026-11-08&per_page=50&'.http_build_query(['employee_uuids' => $uuids]))->assertOk();
            $this->context($this->admin->tenant_id);

            return count(\DB::getQueryLog());
        };
        $small = $count(2);
        $big = $count(12);
        $this->assertLessThanOrEqual($small + 3, $big, "grid queries grew from $small to $big");
        $this->assertLessThan(60, $big);
    }

    public function test_shift_swap_flow_with_manager_approval_delegation_and_atomic_write(): void
    {
        $boss = $this->hire('Maya Chen', ['role' => 'Manager']);
        $asha = $this->hire('Asha Rao', ['manager' => $boss, 'role' => 'Team member']);
        $ben = $this->hire('Ben Lee', ['manager' => $boss, 'role' => 'Team member']);
        $a = $this->shift('A');
        $b = $this->shift('B', '14:00', '22:00');
        $this->week_all();
        $r = $this->roster();
        $this->set($r, $asha, '2026-11-09', $a);
        $this->set($r, $ben, '2026-11-09', $b);
        $this->svc()->publish($r, $this->admin, true);

        $ashaUser = \App\Models\User::find(\DB::table('people')->where('id', $asha->person_id)->value('user_id'));
        $benUser = \App\Models\User::find(\DB::table('people')->where('id', $ben->person_id)->value('user_id'));
        $bossUser = \App\Models\User::find(\DB::table('people')->where('id', $boss->person_id)->value('user_id'));

        $this->signIn($ashaUser);
        $resp = $this->postJson('/api/v1/shift-swaps', ['type' => 'swap', 'work_date' => '2026-11-09', 'counterpart_uuid' => $ben->uuid, 'reason' => 'Family event']);
        $this->assertSame(201, $resp->status(), $resp->getContent());
        $uuid = $resp->json('data.uuid');
        $this->context($ashaUser->tenant_id);
        $this->assertSame(1, WorkItem::where('source_entity', "shift_swap:$uuid:consent")->count());
        // manager cannot decide before the colleague consents
        $this->signIn($bossUser);
        $this->postJson("/api/v1/shift-swaps/$uuid/decision", ['approve' => true])->assertStatus(409);
        $this->signIn($benUser);
        $this->postJson("/api/v1/shift-swaps/$uuid/respond", ['accept' => true])->assertOk()->assertJsonPath('data.status', 'counterpart_accepted');
        $this->context($ashaUser->tenant_id);
        $task = WorkItem::where('source_entity', "shift_swap:$uuid")->first();
        $this->assertSame($bossUser->id, $task->assignee_id);
        $this->assertSame('/time-office/approvals?kind=shift_swap', $task->action);

        // requester and a stranger cannot approve
        $this->signIn($ashaUser);
        $this->postJson("/api/v1/shift-swaps/$uuid/decision", ['approve' => true])->assertForbidden();

        // delegation: boss delegates to another manager
        $this->context($bossUser->tenant_id);
        $deputy = $this->userWithRole('Manager', 'deputy@alpha.test', 'Deputy');
        \DB::table('approval_delegations')->insert(['tenant_id' => $bossUser->tenant_id, 'delegator_user_id' => $bossUser->id, 'delegate_user_id' => $deputy->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31', 'kinds' => null, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($deputy);
        $this->postJson("/api/v1/shift-swaps/$uuid/decision", ['approve' => true, 'comment' => 'ok'])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->context($bossUser->tenant_id);
        $res = app(\App\Domain\Attendance\Contracts\ScheduleResolver::class);
        $this->assertSame('B', $res->resolve($asha->id, '2026-11-09')->shiftCode);
        $this->assertSame('A', $res->resolve($ben->id, '2026-11-09')->shiftCode);
        $this->assertSame(2, RosterEntry::where('status', 'superseded')->count());
        $this->assertSame('completed', WorkItem::where('source_entity', "shift_swap:$uuid")->first()->status);
        $this->assertGreaterThanOrEqual(2, DomainEvent::where('type', 'ShiftChanged')->whereIn('employee_id', [$asha->id, $ben->id])->count());
    }

    public function test_shift_swap_can_exchange_different_dates_and_revalidates_both_assignments(): void
    {
        $boss = $this->hire('Maya Chen', ['role' => 'Manager']);
        $asha = $this->hire('Asha Rao', ['manager' => $boss, 'role' => 'Team member']);
        $ben = $this->hire('Ben Lee', ['manager' => $boss, 'role' => 'Team member']);
        $a = $this->shift('A');
        $b = $this->shift('B', '14:00', '22:00');
        $this->week_all();
        $r = $this->roster('2026-11-02', '2026-11-15');
        $this->set($r, $asha, '2026-11-09', $a);
        $this->set($r, $ben, '2026-11-10', $b);
        $this->svc()->publish($r, $this->admin, true);

        $ashaUser = \App\Models\User::find(\DB::table('people')->where('id', $asha->person_id)->value('user_id'));
        $benUser = \App\Models\User::find(\DB::table('people')->where('id', $ben->person_id)->value('user_id'));
        $bossUser = \App\Models\User::find(\DB::table('people')->where('id', $boss->person_id)->value('user_id'));
        $this->signIn($ashaUser);
        $created = $this->postJson('/api/v1/shift-swaps', [
            'type' => 'swap', 'work_date' => '2026-11-09', 'counterpart_date' => '2026-11-10', 'counterpart_uuid' => $ben->uuid, 'reason' => 'Family event',
        ])->assertCreated()->assertJsonPath('data.counterpart_date', '2026-11-10');
        $uuid = $created->json('data.uuid');

        $this->signIn($benUser);
        $this->postJson("/api/v1/shift-swaps/$uuid/respond", ['accept' => true])->assertOk();
        $this->signIn($bossUser);
        $this->postJson("/api/v1/shift-swaps/$uuid/decision", ['approve' => true])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->context($ashaUser->tenant_id);
        $resolver = app(\App\Domain\Attendance\Contracts\ScheduleResolver::class);
        $this->assertSame('B', $resolver->resolve($asha->id, '2026-11-09')->shiftCode);
        $this->assertSame('A', $resolver->resolve($ben->id, '2026-11-10')->shiftCode);
        $this->assertSame(2, RosterEntry::where('status', 'superseded')->count());
    }

    private function week_all(): void
    {
        $w = app(\App\Domain\Attendance\Scheduling\WorkWeekService::class)->create(['name' => 'All', 'code' => 'ALL', 'days' => array_fill(1, 7, 'full'), 'effective_from' => '2026-01-01'], $this->admin);
        app(ScheduleAssignmentService::class)->create('work_week', ['target_type' => 'tenant', 'work_week_uuid' => $w->uuid, 'effective_from' => '2026-01-01'], $this->admin);
    }

    public function test_swap_restrictions_notice_limits_rest_and_locks(): void
    {
        $boss = $this->hire('Maya Chen', ['role' => 'Manager']);
        $asha = $this->hire('Asha Rao', ['manager' => $boss, 'role' => 'Team member']);
        $ben = $this->hire('Ben Lee', ['manager' => $boss, 'role' => 'Team member', 'department' => 1]);
        $a = $this->shift('A');
        $b = $this->shift('B', '14:00', '22:00');
        $this->week_all();
        $r = $this->roster('2026-10-01', '2026-11-30');
        foreach ([$asha, $ben] as $e) {
            $this->set($r, $e, '2026-10-08', $a);
            $this->set($r, $e, '2026-11-09', $a);
        }
        $this->set($r, $ben, '2026-11-09', $b);
        $this->svc()->publish($r, $this->admin, true);
        $ashaUser = \App\Models\User::find(\DB::table('people')->where('id', $asha->person_id)->value('user_id'));
        $this->signIn($ashaUser);

        // 24h notice: tomorrow's 09:00 IST shift starts in ~23h
        $this->postJson('/api/v1/shift-swaps', ['type' => 'change', 'work_date' => '2026-10-08', 'to_shift_uuid' => $b->uuid, 'reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('work_date');
        // different department than counterpart (same_department_only default)
        $this->postJson('/api/v1/shift-swaps', ['type' => 'swap', 'work_date' => '2026-11-09', 'counterpart_uuid' => $ben->uuid, 'reason' => 'x'])->assertStatus(422)->assertJsonValidationErrors('counterpart_uuid');
        // reason required
        $this->postJson('/api/v1/shift-swaps', ['type' => 'change', 'work_date' => '2026-11-09', 'to_shift_uuid' => $b->uuid])->assertStatus(422)->assertJsonValidationErrors('reason');
        // valid change; then the monthly cap
        $resp = $this->postJson('/api/v1/shift-swaps', ['type' => 'change', 'work_date' => '2026-11-09', 'to_shift_uuid' => $b->uuid, 'reason' => 'Dentist']);
        $this->assertSame(201, $resp->status(), $resp->getContent());
        $tenant = \App\Models\Tenant::find($ashaUser->tenant_id);
        $tenant->settings = array_merge($tenant->settings, ['attendance' => ['swap' => ['max_per_month' => 1]]]);
        $tenant->save();
        $this->postJson('/api/v1/shift-swaps', ['type' => 'change', 'work_date' => '2026-11-09', 'to_shift_uuid' => $b->uuid, 'reason' => 'Again'])->assertStatus(422);
    }
}
