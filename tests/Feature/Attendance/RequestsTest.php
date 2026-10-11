<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Models\AttendanceAdjustment;
use App\Domain\Attendance\Models\Regularization;
use App\Domain\Attendance\Requests\ApproverResolver;
use App\Domain\Events\DomainEvent;
use App\Models\InAppNotification;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

class RequestsTest extends TestCase
{
    use PeopleFixtures, RefreshDatabase;

    private $boss;

    private $emp;

    private $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->hr = $this->hire('Hera HR', ['role' => 'HR administrator', 'email' => 'hr@alpha.test']);
        $this->boss = $this->hire('Maya Boss', ['role' => 'Manager', 'email' => 'maya@alpha.test']);
        $this->emp = $this->hire('Asha Rao', ['role' => 'Team member', 'manager' => $this->boss, 'email' => 'asha@alpha.test']);
    }

    private function user($employee): User
    {
        return User::find(DB::table('people')->where('id', $employee->person_id)->value('user_id'));
    }

    private function as($employee): static
    {
        return $this->signIn($this->user($employee));
    }

    private function reg(array $o = []): array
    {
        return $o + ['date' => '2026-10-06', 'type' => 'forgot_out', 'requested_in' => '09:00', 'requested_out' => '18:00', 'reason' => 'Forgot to punch out'];
    }

    public function test_regularization_lifecycle_utc_tasks_events_and_recalculation(): void
    {
        $calls = [];
        $this->app->instance(AttendanceRecalculator::class, new class($calls) implements AttendanceRecalculator
        {
            public function __construct(public array &$calls) {}

            public function recalculateDay(int $employeeId, string $date, string $trigger, ?int $requestedBy = null): ?int
            {
                $this->calls[] = [$employeeId, $date, $trigger];

                return 1;
            }

            public function recalculate(array $scope, string $trigger, ?int $requestedBy = null, ?string $dedupeKey = null): string
            {
                return 'x';
            }
        });
        $res = $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg())->assertCreated();
        $uuid = $res->json('data.uuid');
        $this->assertSame('2026-10-06T03:30:00+00:00', $res->json('data.requested_in')); // 09:00 IST
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, WorkItem::where('assignee_id', $this->user($this->boss)->id)->where('source_entity', "regularization:$uuid")->where('status', 'open')->count());
        $this->assertTrue(DomainEvent::where('type', 'RegularizationSubmitted')->exists());

        $this->as($this->boss)->postJson("/api/v1/attendance/regularizations/$uuid/approve", ['comment' => 'ok'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, WorkItem::where('source_entity', "regularization:$uuid")->where('status', 'open')->count());
        $this->assertSame([[$this->emp->id, '2026-10-06', 'regularization']], $calls);
        $this->assertTrue(DomainEvent::where('type', 'RegularizationApproved')->exists());
        $this->assertSame(1, InAppNotification::where('user_id', $this->user($this->emp)->id)->where('title', 'Regularization approved')->count());
    }

    public function test_self_approval_is_refused_and_non_approver_cannot_decide(): void
    {
        $uuid = $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg())->json('data.uuid');
        $this->as($this->emp)->postJson("/api/v1/attendance/regularizations/$uuid/approve")->assertStatus(403);
        // a manager submitting for themselves is routed to HR, never to themselves
        $mine = $this->as($this->boss)->postJson('/api/v1/attendance/regularizations', $this->reg())->assertCreated()->json('data.uuid');
        $this->as($this->boss)->postJson("/api/v1/attendance/regularizations/$mine/approve")->assertStatus(403);
        $this->as($this->hr)->postJson("/api/v1/attendance/regularizations/$mine/approve")->assertOk();
    }

    public function test_two_step_flow_and_rejection_needs_comment(): void
    {
        $this->context($this->admin->tenant_id);
        DB::table('tenants')->where('id', $this->admin->tenant_id)->update(['settings' => DB::raw("json_set(settings, '$.attendance.regularization.window_days', 7)")]);
        $uuid = $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg())->json('data.uuid');
        $this->as($this->boss)->postJson("/api/v1/attendance/regularizations/$uuid/reject", [])->assertStatus(422);
        $this->as($this->boss)->postJson("/api/v1/attendance/regularizations/$uuid/reject", ['comment' => 'Not valid'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->as($this->boss)->postJson("/api/v1/attendance/regularizations/$uuid/approve")->assertStatus(409);
    }

    public function test_delegation_routes_to_delegate_and_records_on_behalf_of(): void
    {
        $this->context($this->admin->tenant_id);
        $peer = $this->hire('Dev Peer', ['role' => 'Manager', 'email' => 'dev@alpha.test']);
        $this->as($this->boss)->postJson('/api/v1/attendance/delegations', ['delegate_user' => $peer->uuid, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-30', 'kinds' => ['regularization']])->assertCreated();
        $uuid = $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg())->json('data.uuid');
        $this->context($this->admin->tenant_id);
        $this->assertTrue(WorkItem::where('assignee_id', $this->user($peer)->id)->where('source_entity', "regularization:$uuid")->exists());
        // The delegate needs the data to decide; the resolver authorizes them even though they are not the manager.
        $this->assertTrue(app(ApproverResolver::class)->canDecide($this->user($peer), $this->emp, 'regularization', '2026-10-06'));
        $this->as($peer)->postJson("/api/v1/attendance/regularizations/$uuid/approve")->assertOk();
        $this->context($this->admin->tenant_id);
        $reg = Regularization::byUuid($uuid);
        $this->assertSame($this->user($this->boss)->id, $reg->actions()->where('action', 'approve')->first()->on_behalf_of);
    }

    public function test_window_idempotency_duplicate_and_future_rules(): void
    {
        $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg(['date' => '2026-09-01']))->assertStatus(422);
        $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg(['date' => '2026-10-20']))->assertStatus(422);
        $h = ['Idempotency-Key' => 'abc-1'];
        $a = $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg(), $h)->assertCreated()->json('data.uuid');
        $b = $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg(), $h)->assertOk();
        $this->assertSame($a, $b->json('data.uuid'));
        $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg(['reason' => 'again']))->assertStatus(422);
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Regularization::count());
    }

    public function test_locked_day_refuses_submission(): void
    {
        $this->app->instance(LockGuard::class, new class implements LockGuard
        {
            public function isLocked(int $e, string $d): bool
            {
                return true;
            }

            public function assertUnlocked(int $e, string $d): void
            {
                abort(423, "Attendance for $d is locked.");
            }

            public function lockFor(int $e, string $d): ?array
            {
                return null;
            }
        });
        $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg())->assertStatus(423);
    }

    public function test_overtime_partial_approval_capped_by_calculated(): void
    {
        $this->context($this->admin->tenant_id);
        DB::table('attendance_days')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'employee_id' => $this->emp->id, 'attendance_date' => '2026-10-06', 'status' => 'present',
            'overtime_minutes' => 60, 'engine_version' => 't', 'calculated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $uuid = $this->as($this->emp)->postJson('/api/v1/attendance/overtime', ['work_date' => '2026-10-06', 'mode' => 'post_work', 'requested_minutes' => 90, 'reason' => 'Release'])->assertCreated()->json('data.uuid');
        $this->as($this->boss)->postJson("/api/v1/attendance/overtime/$uuid/approve", ['approved_minutes' => 80])->assertStatus(422);
        $this->as($this->boss)->postJson("/api/v1/attendance/overtime/$uuid/approve", ['approved_minutes' => 60])->assertOk()
            ->assertJsonPath('data.status', 'partially_approved')->assertJsonPath('data.approved_minutes', 60)->assertJsonPath('data.calculated_minutes', 60);
        $this->context($this->admin->tenant_id);
        $this->assertTrue(DomainEvent::where('type', 'OvertimeApproved')->exists());
    }

    public function test_on_duty_overlap_and_remote_flag(): void
    {
        $body = ['type' => 'remote_work', 'start_date' => '2026-10-08', 'end_date' => '2026-10-09', 'reason' => 'WFH'];
        $uuid = $this->as($this->emp)->postJson('/api/v1/attendance/on-duty', $body)->assertCreated()->assertJsonPath('data.is_remote', true)->json('data.uuid');
        $this->as($this->emp)->postJson('/api/v1/attendance/on-duty', ['start_date' => '2026-10-09'] + $body)->assertStatus(422);
        $this->as($this->boss)->postJson("/api/v1/attendance/on-duty/$uuid/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_maker_checker_for_sensitive_adjustment_and_revert_keeps_history(): void
    {
        $body = ['employee' => $this->emp->uuid, 'date' => '2026-09-01', 'type' => 'status_override', 'status' => 'present', 'reason' => 'Verified from CCTV log'];
        $this->as($this->hr)->postJson('/api/v1/attendance/adjustments', $body)->assertCreated()->assertJsonPath('data.status', 'pending_approval');
        $uuid = AttendanceAdjustment::first()?->uuid ?? $this->adjUuid();
        $this->as($this->hr)->postJson("/api/v1/attendance/adjustments/$uuid/approve")->assertStatus(403);
        $this->signIn($this->admin)->postJson("/api/v1/attendance/adjustments/$uuid/approve")->assertOk()->assertJsonPath('data.status', 'applied');
        $rev = $this->signIn($this->admin)->postJson("/api/v1/attendance/adjustments/$uuid/revert", ['reason' => 'Mistake'])->assertCreated()->json('data');
        $this->assertSame('reversal', $rev['type']);
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, AttendanceAdjustment::count());
        $this->as($this->emp)->postJson('/api/v1/attendance/adjustments', $body)->assertStatus(403);
    }

    private function adjUuid(): string
    {
        $this->context($this->admin->tenant_id);

        return AttendanceAdjustment::firstOrFail()->uuid;
    }

    public function test_approvals_inbox_lists_only_decidable_items(): void
    {
        $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg())->assertCreated();
        $this->as($this->boss)->getJson('/api/v1/attendance/approvals?kind=regularization')->assertOk()->assertJsonCount(1, 'data');
        $this->context($this->admin->tenant_id);
        $other = $this->hire('Zed Other', ['role' => 'Manager', 'email' => 'zed@alpha.test']);
        $this->as($other)->getJson('/api/v1/attendance/approvals?kind=regularization')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_bulk_preview_apply_token_tamper_scope_and_partial_failure(): void
    {
        $this->context($this->admin->tenant_id);
        $other = $this->hire('Carl Out', ['role' => 'Team member', 'email' => 'carl@alpha.test']);
        $params = ['type' => 'incorrect', 'reason' => 'Device outage', 'requested_in' => '09:00', 'requested_out' => '18:00'];
        $targets = [['employee' => $this->emp->uuid, 'date' => '2026-10-06'], ['employee' => '00000000-0000-0000-0000-000000000000', 'date' => '2026-10-06'], ['employee' => $other->uuid, 'date' => '2026-10-07']];
        $p = $this->signIn($this->admin)->postJson('/api/v1/attendance/bulk/preview', ['action' => 'regularize', 'params' => $params, 'targets' => $targets])->assertOk();
        $this->assertSame(2, $p->json('affected'));
        $this->assertSame('Not found.', $p->json('results.1.message'));
        $token = $p->json('confirmation_token');
        $this->signIn($this->admin)->postJson('/api/v1/attendance/bulk/apply', ['token' => $token.'x'])->assertStatus(422);
        $this->as($this->hr)->postJson('/api/v1/attendance/bulk/apply', ['token' => $token])->assertStatus(403);
        $a = $this->signIn($this->admin)->postJson('/api/v1/attendance/bulk/apply', ['token' => $token])->assertOk();
        $this->assertSame(2, $a->json('applied'));
        $this->assertSame('partial', $a->json('status'));
        $this->signIn($this->admin)->postJson('/api/v1/attendance/bulk/apply', ['token' => $token])->assertStatus(409);
        // a manager has no attendance.bulk
        $this->as($this->boss)->postJson('/api/v1/attendance/bulk/preview', ['action' => 'regularize', 'params' => $params, 'targets' => $targets])->assertStatus(403);
    }

    public function test_bulk_token_expires(): void
    {
        $p = $this->signIn($this->admin)->postJson('/api/v1/attendance/bulk/preview', ['action' => 'regularize', 'params' => ['reason' => 'x', 'requested_in' => '09:00'], 'targets' => [['employee' => $this->emp->uuid, 'date' => '2026-10-06']]])->assertOk();
        $this->travel(16)->minutes();
        $this->signIn($this->admin)->postJson('/api/v1/attendance/bulk/apply', ['token' => $p->json('confirmation_token')])->assertStatus(422);
    }

    public function test_tenant_isolation_returns_404(): void
    {
        $uuid = $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg())->json('data.uuid');
        $beta = $this->bootTenantTwo();
        $this->signIn($beta)->getJson("/api/v1/attendance/regularizations/$uuid")->assertStatus(404);
    }

    private function bootTenantTwo(): User
    {
        $u = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->context($u->tenant_id);

        return $u;
    }

    public function test_commands_are_idempotent_and_expire_old_requests(): void
    {
        $uuid = $this->as($this->emp)->postJson('/api/v1/attendance/regularizations', $this->reg(['date' => '2026-10-01']))->json('data.uuid');
        $this->travel(3)->days();
        $this->artisan('attendance:expire-requests')->assertExitCode(0);
        $this->artisan('attendance:expire-requests')->assertExitCode(0);
        $this->context($this->admin->tenant_id);
        $this->assertSame('expired', Regularization::byUuid($uuid)->status);
        $this->assertSame(1, InAppNotification::where('title', 'Regularization expired')->count());
        $this->artisan('attendance:send-reminders')->assertExitCode(0);
        $this->artisan('attendance:send-reminders')->assertExitCode(0);
    }

    public function test_notification_dedupe(): void
    {
        $support = app(\App\Domain\Attendance\Requests\RequestSupport::class);
        $uid = $this->user($this->emp)->id;
        $this->assertTrue($support->notify($uid, 'T', 'B', 'k1'));
        $this->assertFalse($support->notify($uid, 'T', 'B', 'k1'));
    }
}
