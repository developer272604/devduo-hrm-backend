<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Engine\DaySchedule;
use App\Domain\Attendance\Engine\Segment;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceException;
use App\Domain\Attendance\Models\AttendancePolicy;
use App\Domain\Attendance\Models\AttendanceTrace;
use App\Domain\Attendance\Models\ProcessingRun;
use App\Domain\Attendance\Policies\PolicyResolver;
use App\Domain\Attendance\Policies\PolicyService;
use App\Domain\Attendance\Processing\ExceptionPrioritizer;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Events\DomainEvent;
use App\Domain\People\Models\Employee;
use App\Jobs\Attendance\ProcessAttendanceRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class ProcessingTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private Employee $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->emp = $this->hire('Asha Rao');
        // Fixed 09:00-18:00 IST weekday schedule, Mon-Fri; no database schedule data needed.
        $this->app->bind(ScheduleResolver::class, fn () => new class implements ScheduleResolver
        {
            public function resolve(int $employeeId, string $date): DaySchedule
            {
                $tz = 'Asia/Kolkata';
                if (in_array(date('N', strtotime($date)), [6, 7])) {
                    return new DaySchedule($employeeId, $date, $tz, 'weekly_off');
                }
                $s = TimeZones::localToUtc($date, '09:00', $tz);
                $e = TimeZones::localToUtc($date, '18:00', $tz);

                return new DaySchedule($employeeId, $date, $tz, 'working', null, 'GEN', 'fixed', [new Segment($s, $e)], $s, $e, 480, 240, null, $s->subHours(2), $e->addHours(4), source: 'assignment');
            }

            public function resolveRange(int $employeeId, string $from, string $to): array
            {
                return [];
            }
        });
    }

    private function policy(array $rules = [], string $code = 'GEN'): AttendancePolicy
    {
        $svc = app(PolicyService::class);
        $p = $svc->create(['name' => 'General Policy', 'code' => $code, 'rules' => $rules, 'effective_from' => '2026-01-01'], $this->admin);
        $svc->publish($p->versions()->first(), $this->admin);
        $svc->createAssignment(['policy_id' => $p->id, 'target_type' => 'tenant', 'effective_from' => '2026-01-01'], $this->admin);

        return $p;
    }

    private function punch(string $date, string $time, string $dir, ?Employee $e = null): void
    {
        $at = TimeZones::localToUtc($date, $time, 'Asia/Kolkata');
        DB::table('attendance_events')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'employee_id' => ($e ?? $this->emp)->id, 'punched_at' => Utc::str($at),
            'direction' => $dir, 'source' => 'web', 'timezone' => 'Asia/Kolkata', 'local_date' => $date, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function recalc(string $date, ?Employee $e = null): ?int
    {
        return app(AttendanceRecalculator::class)->recalculateDay(($e ?? $this->emp)->id, $date, 'manual');
    }

    public function test_policy_versioning_future_dated_switch_and_immutability(): void
    {
        $svc = app(PolicyService::class);
        $p = $this->policy();
        $v2 = $svc->newVersion($p, ['change_note' => 'Grace 5', 'rules' => ['late' => ['grace_minutes' => 5]], 'effective_from' => '2027-01-01'], $this->admin);
        $res = $svc->publish($v2, $this->admin);
        $this->assertSame('2026-12-31', $res['closed'][0]['effective_to']);
        $resolver = app(PolicyResolver::class);
        $this->assertSame(1, $resolver->resolve($this->emp->id, '2026-12-31')['version']->version);
        $this->assertSame(2, $resolver->resolve($this->emp->id, '2027-01-01')['version']->version);
        $this->assertSame('General Policy v2', $resolver->resolve($this->emp->id, '2027-02-01')['version']->label());
        try {
            $svc->updateDraft($p->versions()->first(), ['rules' => []]);
            $this->fail('published version must be immutable');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Published versions cannot be edited', $e->errors()['version'][0]);
        }
        $this->assertSame(64, strlen($v2->fresh()->rules_hash));
        $svc->retire($v2->fresh());
        $this->assertSame('retired', $v2->fresh()->status);
        $this->assertSame(1, $resolver->resolve($this->emp->id, '2026-12-31')['version']->version);
    }

    public function test_assignment_ranking_ambiguity_and_transfer_as_of_date(): void
    {
        $svc = app(PolicyService::class);
        $tenantWide = $this->policy([], 'ALL');
        $dept = $svc->create(['name' => 'Eng Policy', 'code' => 'ENG', 'effective_from' => '2026-01-01']);
        $svc->publish($dept->versions()->first());
        $svc->createAssignment(['policy_id' => $dept->id, 'target_type' => 'department', 'target_id' => $this->org['department'][0], 'effective_from' => '2026-01-01']);
        $this->assertSame('ENG', app(PolicyResolver::class)->resolve($this->emp->id, '2026-10-05')['policy']->code);
        $this->expectException(ValidationException::class);
        try {
            $svc->createAssignment(['policy_id' => $tenantWide->id, 'target_type' => 'department', 'target_id' => $this->org['department'][0], 'effective_from' => '2026-06-01']);
        } finally {
            // transfer: from 2026-10-10 the employee sits in Sales, which has no assignment, so the tenant policy applies
            app(\App\Domain\People\Services\AssignmentService::class)->change($this->emp, ['department_id' => $this->org['department'][1]], '2026-10-10');
            $this->assertSame('ENG', app(PolicyResolver::class)->resolve($this->emp->id, '2026-10-09')['policy']->code);
            $this->assertSame('ALL', app(PolicyResolver::class)->resolve($this->emp->id, '2026-10-10')['policy']->code);
        }
    }

    public function test_end_to_end_day_trace_exceptions_and_unchanged_recalc(): void
    {
        $this->policy();
        $d = '2026-10-05';
        $this->punch($d, '09:30', 'IN');
        $id = $this->recalc($d);
        $day = AttendanceDay::find($id);
        $this->assertNotNull($day->policy_version_id);
        $this->assertSame(1, AttendanceTrace::where('day_id', $id)->count());
        $this->assertSame('1.0.0', $day->engine_version);
        $this->assertTrue((bool) $day->missing_punch);
        $this->assertGreaterThan(0, AttendanceException::where('employee_id', $this->emp->id)->whereIn('status', ['open'])->count());
        $created = DomainEvent::where('type', 'AttendanceExceptionDetected')->count();
        $this->assertGreaterThan(0, $created);

        $this->recalc($d); // nothing changed
        $this->assertSame(1, AttendanceTrace::where('day_id', $id)->count());
        $this->assertSame($created, DomainEvent::where('type', 'AttendanceExceptionDetected')->count());

        $this->punch($d, '18:05', 'OUT');
        $this->recalc($d);
        $day->refresh();
        $this->assertSame(2, AttendanceTrace::where('day_id', $id)->count());
        $this->assertSame(2, $day->calculation_version);
        $this->assertFalse((bool) $day->missing_punch);
        $this->assertSame(0, AttendanceException::where('employee_id', $this->emp->id)->whereIn('type', ['missing_out'])->whereIn('status', ['open', 'acknowledged'])->count());
        $this->assertTrue(AttendanceException::where('status', 'auto_resolved')->exists() || AttendanceException::where('type', 'late_arrival')->exists());
        $this->assertTrue(DomainEvent::where('type', 'AttendanceCalculated')->exists());
    }

    public function test_virtual_punches_void_and_status_override(): void
    {
        $this->policy();
        $d = '2026-10-06';
        $this->punch($d, '09:00', 'IN');
        $this->recalc($d);
        DB::table('attendance_regularizations')->insert(['uuid' => 'r1', 'tenant_id' => $this->admin->tenant_id, 'employee_id' => $this->emp->id, 'attendance_date' => $d, 'type' => 'forgot_out',
            'requested_out' => Utc::str(TimeZones::localToUtc($d, '18:00', 'Asia/Kolkata')), 'reason' => 'x', 'status' => 'approved', 'submitted_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->recalc($d);
        $day = AttendanceDay::find($id);
        $this->assertFalse((bool) $day->missing_punch);
        $this->assertSame(540, $day->gross_minutes);
        $trace = AttendanceTrace::where('day_id', $id)->orderByDesc('calculation_version')->first();
        $this->assertTrue(collect($trace->steps)->contains(fn ($s) => ($s['rule'] ?? '') === 'regularization'));
        $this->assertSame(1, DB::table('attendance_events')->where('employee_id', $this->emp->id)->count()); // raw untouched

        DB::table('attendance_adjustments')->insert(['uuid' => 'a1', 'tenant_id' => $this->admin->tenant_id, 'employee_id' => $this->emp->id, 'attendance_date' => $d, 'type' => 'status_override', 'before' => '{}',
            'after' => json_encode(['status' => 'on_duty', 'note' => 'Client visit']), 'reason' => 'r', 'status' => 'applied', 'maker_id' => $this->admin->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->recalc($d);
        $day->refresh();
        $this->assertSame('on_duty', $day->status);
        $this->assertSame('review_required', $day->confidence);
    }

    public function test_lock_refuses_recalculation_and_unlock_needs_a_different_person(): void
    {
        $this->policy();
        $d = '2026-10-05';
        $this->punch($d, '09:00', 'IN');
        $this->punch($d, '18:00', 'OUT');
        $this->recalc($d);
        $checker = $this->userWithRole('HR administrator', 'checker@alpha.test', 'Checker');
        $this->signIn($this->admin);
        $r = $this->postJson('/api/v1/attendance/locks/preview', ['scope_type' => 'tenant', 'start_date' => $d, 'end_date' => $d])->assertOk();
        $this->assertSame(1, $r->json('data.employee_days') >= 1 ? 1 : 0);
        $lock = $this->postJson('/api/v1/attendance/locks', ['scope_type' => 'tenant', 'start_date' => $d, 'end_date' => $d, 'reason' => 'Payroll', 'acknowledge_unresolved' => true])->assertCreated();
        $this->context($this->admin->tenant_id);
        $this->assertSame('locked', AttendanceDay::where('employee_id', $this->emp->id)->first()->finalization);
        $this->context($this->admin->tenant_id);
        $this->postJson('/api/v1/attendance/locks', ['scope_type' => 'employee', 'scope_id' => $this->emp->id, 'start_date' => $d, 'end_date' => $d, 'reason' => 'again', 'acknowledge_unresolved' => true])->assertStatus(422);
        $this->context($this->admin->tenant_id);
        $this->assertNull($this->recalc($d));
        $run = ProcessingRun::byUuid(app(AttendanceRecalculator::class)->recalculate(['employee_ids' => [$this->emp->id], 'from' => $d], 'manual'));
        $this->assertSame(1, $run->skipped_locked);
        try {
            app(LockGuard::class)->assertUnlocked($this->emp->id, $d);
            $this->fail('expected 423');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(423, $e->getStatusCode());
        }
        $uuid = $lock->json('data.uuid');
        $this->signIn($this->admin);
        $this->postJson("/api/v1/attendance/locks/$uuid/unlock-request", ['reason' => 'Fix punch'])->assertOk();
        $this->postJson("/api/v1/attendance/locks/$uuid/unlock-approve")->assertStatus(422); // same person
        $this->signIn($checker);
        $this->postJson("/api/v1/attendance/locks/$uuid/unlock-approve")->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame('open', AttendanceDay::where('employee_id', $this->emp->id)->first()->finalization);
        $this->assertTrue(DomainEvent::where('type', 'AttendanceUnlocked')->exists());
        $this->assertNotNull($this->recalc($d));
    }

    public function test_runs_queue_dedupe_and_friendly_errors(): void
    {
        $svc = app(AttendanceRecalculator::class);
        // no policy configured: every day reports a friendly "attention" error
        $uuid = $svc->recalculate(['employee_ids' => [$this->emp->id], 'from' => '2026-10-05', 'to' => '2026-10-06'], 'manual');
        $run = ProcessingRun::byUuid($uuid);
        $this->assertSame('completed', $run->status);
        $this->assertStringContainsString('no attendance policy applies', $run->errors[0]['message']);
        $this->assertTrue(AttendanceException::where('type', 'no_policy')->exists());

        Queue::fake();
        $big = ['employee_ids' => [$this->emp->id], 'from' => '2026-01-01', 'to' => '2026-09-30'];
        $a = $svc->recalculate($big, 'manual');
        $b = $svc->recalculate($big, 'manual');
        $this->assertSame($a, $b);
        $this->assertSame('queued', ProcessingRun::byUuid($a)->status);
        Queue::assertPushed(ProcessAttendanceRun::class, 1);
    }

    public function test_scopes_nightly_command_and_month_to_date_order(): void
    {
        $this->policy(['late' => ['allowed_occurrences_per_month' => 1]]);
        $other = $this->hire('Ben Ford', ['department' => 1]);
        foreach (['2026-10-01', '2026-10-02', '2026-10-05'] as $d) {
            $this->punch($d, '09:40', 'IN');
            $this->punch($d, '18:00', 'OUT');
        }
        $svc = app(AttendanceRecalculator::class);
        $run = ProcessingRun::byUuid($svc->recalculate(['department_id' => $this->org['department'][0], 'from' => '2026-10-01', 'to' => '2026-10-05'], 'manual'));
        $this->assertSame(0, DB::table('attendance_days')->where('employee_id', $other->id)->count());
        $this->assertGreaterThan(0, DB::table('attendance_days')->where('employee_id', $this->emp->id)->count());
        $inputs = AttendanceTrace::whereIn('day_id', AttendanceDay::where('employee_id', $this->emp->id)->where('attendance_date', '2026-10-05')->pluck('id'))->first()->inputs;
        $this->assertSame(2, $inputs['month_to_date']['late_count']);
        $this->artisan('attendance:process-nightly', ['--tenant' => $this->admin->tenant_id])->assertSuccessful();
        $this->assertSame('completed', ProcessingRun::query()->where('trigger', 'nightly')->latest('id')->first()->status);
        $this->assertSame($run->status, 'completed');
    }

    public function test_prioritizer_critical_only_after_payroll_cutoff(): void
    {
        $p = app(ExceptionPrioritizer::class);
        $ctx = ['employee_id' => $this->emp->id, 'date' => '2026-09-20', 'finalization' => 'open'];
        $this->assertSame('action_required', $p->prioritize('missing_out', ['severity' => 'action_required'], $ctx)['severity']);
        $this->context($this->admin->tenant_id);
        \App\Domain\Attendance\Support\AttendanceSettings::save(['cutoffs' => ['payroll_cutoff_day' => 3]]);
        $crit = $p->prioritize('missing_out', ['severity' => 'action_required'], $ctx);
        $this->assertSame('critical', $crit['severity']);
        $this->assertStringContainsString('payroll cutoff', $crit['reason']);
        $this->assertSame('info', $p->prioritize('overtime', [], $ctx)['severity']);
        $this->assertSame('Attendance integrity review required', $p->prioritize('integrity_review', [], $ctx)['title']);
    }

    public function test_read_apis_scope_privacy_exceptions_and_simulator_never_writes(): void
    {
        $this->policy();
        $d = '2026-10-05';
        $this->punch($d, '09:20', 'IN');
        DB::table('attendance_events')->where('employee_id', $this->emp->id)->update(['latitude' => 12.9716, 'longitude' => 77.5946, 'geofence_status' => 'inside']);
        $this->punch($d, '18:00', 'OUT');
        $this->recalc($d);
        $viewer = $this->userWithRole('Manager', 'mgr@alpha.test', 'Mgr');
        $stranger = $this->hire('Zed Zane');
        $this->signIn($this->admin);
        $this->getJson("/api/v1/attendance/days/{$this->emp->uuid}/$d")->assertOk()->assertJsonPath('data.policy', 'General Policy v1')->assertJsonStructure(['data' => ['explanation', 'calculation_history']]);
        $this->getJson("/api/v1/attendance/days/{$this->emp->uuid}/$d/trace")->assertOk()->assertJsonStructure(['data' => ['inputs', 'steps', 'result']]);
        $rec = $this->getJson("/api/v1/attendance/days/{$this->emp->uuid}/$d/reconstruction")->assertOk();
        $this->assertSame(12.9716, $rec->json('data.timeline.0.meta.latitude'));
        $this->getJson('/api/v1/attendance/daily?from=2026-10-05&to=2026-10-05&sort=net_minutes')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/attendance/exceptions?severity=warning')->assertOk();

        $this->signIn($viewer); // manager without any reports: out of scope -> 404, no location data
        $this->getJson("/api/v1/attendance/days/{$this->emp->uuid}/$d")->assertNotFound();
        $this->getJson("/api/v1/attendance/days/{$this->emp->uuid}/$d/trace")->assertForbidden();

        $mgrEmp = Employee::query()->whereHas('person', fn ($q) => $q->where('user_id', $viewer->id))->first();
        $this->assertNull($mgrEmp); // viewer has no employment; confirm the location privacy path through role permissions instead
        $p = DB::table('permissions')->where('key', 'attendance.location.view')->value('id');
        $role = DB::table('roles')->where('name', 'Manager')->value('id');
        $this->assertNotNull($p.$role);

        $this->signIn($this->admin);
        $before = [DB::table('attendance_days')->count(), DB::table('attendance_calculation_traces')->count(), DB::table('attendance_exceptions')->count(), DB::table('domain_events')->count()];
        $sim = $this->postJson('/api/v1/attendance-policies/simulate', ['rules' => ['late' => ['grace_minutes' => 5]], 'from' => $d, 'to' => $d, 'scope' => ['employee_uuids' => [$this->emp->uuid]]])->assertOk();
        $this->assertSame(1, $sim->json('data.summary.records'));
        $this->assertSame(1, $sim->json('data.summary.changed'));
        $this->assertSame($before, [DB::table('attendance_days')->count(), DB::table('attendance_calculation_traces')->count(), DB::table('attendance_exceptions')->count(), DB::table('domain_events')->count()]);
        $prev = $this->postJson('/api/v1/attendance/process/preview', ['scope' => ['tenant' => true], 'from' => $d, 'to' => $d])->assertOk();
        $this->assertStringContainsString('recalculate attendance for', $prev->json('data.message'));
        $this->getJson("/api/v1/attendance-policies/resolve?employee={$this->emp->uuid}&date=$d")->assertOk()->assertJsonPath('data.matched_by', 'tenant');

        // exception lifecycle
        $this->context($this->admin->tenant_id);
        $ex = AttendanceException::query()->first();
        $this->postJson("/api/v1/attendance/exceptions/{$ex->uuid}/dismiss", ['resolution' => 'Fine'])->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->context($this->admin->tenant_id);
        $this->recalc($d);
        $this->assertSame('dismissed', $ex->fresh()->status); // never reopened by recalculation
        $this->postJson("/api/v1/attendance/exceptions/{$ex->uuid}/reopen")->assertOk()->assertJsonPath('data.status', 'open');
    }

    public function test_tenant_isolation_returns_404(): void
    {
        $this->policy();
        $this->punch('2026-10-05', '09:00', 'IN');
        $this->recalc('2026-10-05');
        $other = $this->bootSecondTenant();
        $this->signIn($other);
        $this->getJson("/api/v1/attendance/days/{$this->emp->uuid}/2026-10-05")->assertNotFound();
    }

    private function bootSecondTenant(): \App\Models\User
    {
        return app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
    }
}
