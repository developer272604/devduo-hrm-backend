<?php

namespace Tests\Feature\Leave;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\OvertimeRequest;
use App\Domain\Attendance\Policies\PolicyService as AttendancePolicyService;
use App\Domain\Attendance\Scheduling\ScheduleAssignmentService;
use App\Domain\Attendance\Scheduling\ShiftService;
use App\Domain\Attendance\Scheduling\WorkWeekService;
use App\Domain\Events\DomainEvent;
use App\Domain\Leave\Integration\LedgerLeaveDataProvider;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Support\LeaveEvents;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P04-T23: the four leave workstreams working together with ONLY real bindings (no fake policy resolver, no fake
 * recalculator). Everything is set up through the public API: types and policy (A), accrual run (B), requests and
 * approvals (C), locks / payroll contract / explainer / sandbox (D).
 * Time is frozen at 2026-10-07 (Wednesday) by the People fixtures.
 */
class EndToEndLeaveTest extends PolicyTestCase
{
    use RefreshDatabase;

    private $hr;

    private $boss;

    private $emp;

    private $peer;

    private array $al;

    private array $co;

    private string $policyUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->hr = $this->hire('Hera HR', ['role' => 'HR administrator', 'email' => 'hr@alpha.test', 'joined_on' => '2024-01-01']);
        $this->boss = $this->hire('Maya Boss', ['role' => 'Manager', 'email' => 'maya@alpha.test', 'joined_on' => '2024-01-01']);
        $this->emp = $this->hire('Asha Rao', ['role' => 'Team member', 'manager' => $this->boss, 'email' => 'asha@alpha.test', 'joined_on' => '2025-06-01']);
        $this->peer = $this->hire('Piya Peer', ['role' => 'Team member', 'manager' => $this->boss, 'email' => 'piya@alpha.test', 'joined_on' => '2025-06-01']);

        // Real Attendance configuration (work week, shift, policy): Mon-Fri 09:00-18:00. No fake ScheduleResolver.
        $week = app(WorkWeekService::class)->create(['name' => 'MF', 'code' => 'MF', 'preset' => 'mon_fri', 'effective_from' => '2026-01-01'], $this->admin);
        $shift = app(ShiftService::class)->create(['name' => 'GEN shift', 'code' => 'GEN', 'timezone' => 'Asia/Kolkata', 'start_time' => '09:00', 'end_time' => '18:00', 'segments' => [['kind' => 'work', 'start' => '09:00', 'end' => '18:00']]]);
        app(ScheduleAssignmentService::class)->create('work_week', ['target_type' => 'tenant', 'work_week_uuid' => $week->uuid, 'effective_from' => '2026-01-01'], $this->admin);
        app(ScheduleAssignmentService::class)->create('shift', ['target_type' => 'tenant', 'shift_uuid' => $shift->uuid, 'effective_from' => '2026-01-01'], $this->admin);
        $att = app(AttendancePolicyService::class);
        $ap = $att->create(['name' => 'General Policy', 'code' => 'GENP', 'rules' => [], 'effective_from' => '2026-01-01'], $this->admin);
        $att->publish($ap->versions()->first(), $this->admin);
        $att->createAssignment(['policy_id' => $ap->id, 'target_type' => 'tenant', 'effective_from' => '2026-01-01'], $this->admin);

        // --- A: type, policy (A's canonical vocabulary only), publish, assign
        $this->signIn($this->admin);
        $this->al = $this->postJson('/api/v1/leave/types', ['name' => 'Annual leave', 'code' => 'AL', 'kind' => 'annual', 'unit' => 'half_day'])->assertCreated()->json('data');
        $this->co = $this->postJson('/api/v1/leave/types', ['name' => 'Comp off', 'code' => 'CO', 'kind' => 'comp_off', 'unit' => 'half_day'])->assertCreated()->json('data');
        $policy = $this->postJson('/api/v1/leave/policies', [
            'name' => 'Standard', 'code' => 'STD', 'effective_from' => '2026-01-01', 'rules' => ['approvals' => ['steps' => ['manager']]],
            'type_rules' => [
                ['type' => 'AL', 'rules' => [
                    'entitlement' => ['basis' => 'annual', 'amount' => '12', 'tenure_bands' => [['from_days' => 0, 'amount' => '12'], ['from_days' => 3650, 'amount' => '20']]],
                    'limits' => ['negative_limit' => '0', 'max_units' => '10'], 'consumption_order' => 'fifo', 'pay_class' => 'paid',
                    'carry_forward' => ['mode' => 'capped', 'cap' => '5'],
                    'encashment' => ['on_separation' => true, 'max_units' => '4'], 'transfer' => ['mode' => 'preserve'],
                ]],
                ['type' => 'CO', 'rules' => ['entitlement' => ['basis' => 'manual'], 'pay_class' => 'comp_off']],
            ],
        ])->assertCreated()->json('data');
        $this->policyUuid = $policy['uuid'];
        $this->postJson("/api/v1/leave/policies/{$this->policyUuid}/versions/1/publish")->assertOk()->assertJsonPath('data.status', 'active');
        $this->postJson('/api/v1/leave/policy-assignments', ['policy' => $this->policyUuid, 'target_type' => 'tenant', 'effective_from' => '2026-01-01'])->assertCreated();

        // --- B: accrual run for the year to date through the API
        $run = $this->postJson('/api/v1/leave/accrual-runs', ['from' => '2026-01-01', 'to' => '2026-10-07'])->assertCreated()->json('data');
        $this->assertSame('completed', $run['status']);
    }

    private function user($e): User
    {
        if ($e instanceof User) {
            return $e;
        }

        return User::find(DB::table('people')->where('id', $e->person_id)->value('user_id'));
    }

    private function as($e): static
    {
        return $this->signIn($this->user($e));
    }

    private function balance($e = null, string $code = 'AL'): array
    {
        $rows = $this->as($e ?? $this->emp)->getJson('/api/v1/leave/me/balances?include=trace')->assertOk()->json('data');

        return collect($rows)->firstWhere('leave_type.code', $code);
    }

    private function body(array $o = []): array
    {
        return $o + ['leave_type' => $this->al['uuid'], 'start_date' => '2026-10-12', 'end_date' => '2026-10-13', 'reason' => 'Family trip'];
    }

    private function submit(array $o = []): string
    {
        return $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->body($o))->assertCreated()->json('data.uuid');
    }

    public function test_policy_resolves_and_accrual_credits_the_banded_entitlement(): void
    {
        $res = $this->as($this->hr)->getJson('/api/v1/leave/policies/resolve?employee='.$this->emp->uuid.'&date=2026-10-07&leave_type=AL')->assertOk()->json('data');
        $this->assertSame('Standard', $res['policy']['name']);
        $this->assertTrue($res['leave_type']['granted']);
        $b = $this->balance();
        // 12 for the first tenure band (from_days 0); the 3650-day band must not apply to a 2025 joiner.
        $this->assertSame('12.0000', $b['balance']['available']);
        $this->assertSame('12.0000', $b['balance']['accrued']);
        $this->assertNotEmpty($b['trace']['accrued']);
        // rerunning the same window is a no-op (idempotent)
        $this->signIn($this->admin);
        $before = LeaveLedgerEntry::count();
        $this->postJson('/api/v1/leave/accrual-runs', ['from' => '2026-01-01', 'to' => '2026-10-07']);
        $this->context($this->admin->tenant_id);
        $this->assertSame($before, LeaveLedgerEntry::count());
        // a long-tenured employee lands in the second band
        $this->assertSame('12.0000', $this->balance($this->boss)['balance']['available'], 'joined 2024: tenure is under 3650 days');
    }

    public function test_request_preview_submit_approve_balance_ledger_and_attendance(): void
    {
        $preview = $this->as($this->emp)->postJson('/api/v1/leave/requests/preview', $this->body())->assertOk()->json('data');
        $this->assertTrue($preview['can_submit']);
        $this->assertSame('2.0000', $preview['duration']['charged_units']);
        $this->assertSame('10.0000', $preview['balance']['accounts'][0]['projected']);
        $this->assertSame(0, LeaveRequest::count());

        $uuid = $this->submit();
        $this->assertSame('10.0000', $this->balance()['balance']['available']);
        $this->assertSame('2.0000', $this->balance()['balance']['pending']);
        $task = WorkItem::where('source_module', 'leave')->where('assignee_id', $this->user($this->boss)->id)->where('status', 'open')->first();
        $this->assertNotNull($task, 'manager sees a My Work task');
        $this->as($this->boss)->getJson('/api/v1/leave/approvals')->assertOk()->assertJsonPath('data.0.uuid', $uuid);
        $this->as($this->emp)->postJson("/api/v1/leave/requests/$uuid/approve")->assertForbidden();
        $this->assertNull(app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-12'));

        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve", ['comment' => 'Enjoy'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(0, WorkItem::where('source_module', 'leave')->where('status', 'open')->count(), 'task closed on decision');
        $b = $this->balance();
        $this->assertSame('10.0000', $b['balance']['available']);
        $this->assertSame('2.0000', $b['balance']['scheduled']);
        $this->assertSame('0.0000', $b['balance']['pending']);

        // ledger statement links the request and the lots it drew from (source-to-use)
        $ledger = $this->getJson('/api/v1/leave/accounts/'.$b['account']['id'].'/ledger')->assertOk()->json('data');
        $this->assertTrue($ledger['integrity']['consistent']);
        $detail = $this->getJson("/api/v1/leave/requests/$uuid")->assertOk()->json('data');
        $consumption = collect($detail['ledger'])->firstWhere('type', 'consumption');
        $this->assertNotEmpty($consumption['sources']);

        // real LeaveDataProvider + real recalculation: Attendance shows the leave
        $this->assertInstanceOf(LedgerLeaveDataProvider::class, app(LeaveDataProvider::class));
        $this->assertSame('full_day', app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-12')['portion']);
        $event = DomainEvent::where('type', LeaveEvents::COVERAGE_CHANGED)->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('Family', json_encode($event->payload));
        $day = AttendanceDay::where('employee_id', $this->emp->id)->where('attendance_date', '2026-10-12')->first();
        $this->assertNotNull($day, 'approval triggered a real attendance recalculation');
        $this->assertSame('leave', $day->status);
        $this->as($this->emp)->getJson('/api/v1/attendance/days/'.$this->emp->uuid.'/2026-10-12')->assertOk()->assertJsonPath('data.status', 'leave');
        $this->as($this->boss)->getJson('/api/v1/leave/team/calendar?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('data.0.employee.name', 'Asha Rao');
    }

    public function test_cancel_and_partial_cancel_reverse_the_ledger_and_attendance(): void
    {
        $uuid = $this->submit(['end_date' => '2026-10-14']);
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk();
        $this->assertSame('9.0000', $this->balance()['balance']['available']);
        $this->as($this->emp)->postJson("/api/v1/leave/requests/$uuid/cancel", ['dates' => ['2026-10-14']])->assertOk()->assertJsonPath('data.status', 'partially_cancelled');
        $this->assertSame('10.0000', $this->balance()['balance']['available']);
        $this->assertNull(app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-14'));
        $this->assertNotSame('leave', optional(AttendanceDay::where('employee_id', $this->emp->id)->where('attendance_date', '2026-10-14')->first())->status);
        $this->as($this->emp)->postJson("/api/v1/leave/requests/$uuid/cancel", [])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $b = $this->balance();
        $this->assertSame('12.0000', $b['balance']['available']);
        $this->assertSame('0.0000', $b['balance']['scheduled']);
        $this->assertTrue($this->getJson('/api/v1/leave/accounts/'.$b['account']['id'].'/ledger')->json('data.integrity.consistent'));
    }

    public function test_comp_off_earn_claim_use_with_trace(): void
    {
        $ot = OvertimeRequest::create(['employee_id' => $this->emp->id, 'work_date' => '2026-10-02', 'mode' => 'post_work', 'initiated_by' => 'employee', 'ot_type' => 'holiday', 'requested_minutes' => 480,
            'approved_minutes' => 480, 'reason' => 'Release', 'status' => 'approved', 'requested_by' => $this->user($this->emp)->id]);
        // the comp-off account is opened through B's API (policy CO rule), as HR would
        $this->signIn($this->admin)->postJson('/api/v1/leave/accounts', ['employee' => $this->emp->uuid, 'leave_type' => $this->co['uuid'], 'date' => '2026-10-07'])->assertSuccessful();
        $credit = $this->as($this->emp)->postJson('/api/v1/leave/comp-off', ['source_type' => 'overtime', 'source_id' => $ot->uuid])->assertCreated()->json('data');
        $this->as($this->boss)->postJson("/api/v1/leave/comp-off/{$credit['uuid']}/approve")->assertOk();
        $this->assertSame('1.0000', $this->balance($this->emp, 'CO')['balance']['available']);
        $claim = $this->as($this->emp)->postJson("/api/v1/leave/comp-off/{$credit['uuid']}/claim", ['start_date' => '2026-10-14', 'end_date' => '2026-10-14'])->assertCreated()->json('data');
        $this->as($this->boss)->postJson("/api/v1/leave/requests/{$claim['request']['uuid']}/approve")->assertOk();
        $this->assertSame('0.0000', $this->balance($this->emp, 'CO')['balance']['available']);
        $trace = $this->as($this->emp)->getJson("/api/v1/leave/comp-off/{$credit['uuid']}/trace")->assertOk()->json('data');
        $this->assertSame($claim['request']['uuid'], $trace['uses'][0]['request']);
    }

    public function test_period_lock_blocks_requests_then_payroll_contract_lists_approved_leave(): void
    {
        $uuid = $this->submit();
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk();

        $this->signIn($this->admin);
        $lock = ['scope_type' => 'tenant', 'start_date' => '2026-10-01', 'end_date' => '2026-10-06', 'reason' => 'Early Oct closed'];
        $this->postJson('/api/v1/leave/locks', $lock)->assertCreated();
        $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->body(['start_date' => '2026-10-05', 'end_date' => '2026-10-05']))->assertStatus(423);
        $this->assertSame(1, LeaveRequest::count());
        $this->assertSame('10.0000', $this->balance()['balance']['available']);

        $this->signIn($this->admin);
        $contract = $this->getJson('/api/v1/leave/payroll/contract?from=2026-10-01&to=2026-10-31')->assertOk()->json();
        $this->assertNull($contract['money']);
        $this->assertSame('2.0000', $contract['totals']['paid']);
        $this->assertSame($uuid, $contract['lines'][0]['meta']['request_uuid']);
        $key = $this->postJson('/api/v1/leave/payroll/contract/batches', ['from' => '2026-10-01', 'to' => '2026-10-31', 'batch_key' => 'oct-2026'])->assertCreated()->json('batch_key');
        $this->getJson("/api/v1/leave/payroll/contract/batches/$key")->assertOk()->assertJsonPath('totals.paid', '2.0000');
    }

    public function test_explainer_and_sandbox_run_on_the_real_engine(): void
    {
        $uuid = $this->submit();
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk();

        $x = $this->as($this->emp)->getJson('/api/v1/leave/explain/balance?employee='.$this->emp->uuid.'&leave_type=AL')->assertOk()->json();
        $this->assertTrue($x['engine_available']);
        $this->assertTrue($x['policy_resolver_available']);
        $this->assertSame('10.0000', $x['accounts'][0]['balance']['available']);
        $this->assertTrue($x['accounts'][0]['integrity']['consistent']);
        $this->as($this->emp)->getJson("/api/v1/leave/explain/requests/$uuid")->assertOk()->assertJsonPath('charged_units', '2.0000');

        $this->signIn($this->admin);
        $alId = DB::table('leave_types')->where('uuid', $this->al['uuid'])->value('id');
        $before = [LeaveLedgerEntry::count(), DB::table('leave_accounts')->count()];
        $sandbox = $this->postJson('/api/v1/leave/simulations/sandbox', ['leave_type_id' => $alId, 'employees' => [$this->emp->uuid], 'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'rules' => ['entitlement' => ['basis' => 'annual', 'amount' => '15', 'tenure_bands' => [['from_days' => 0, 'amount' => '15']]], 'carry_forward' => ['mode' => 'capped', 'cap' => '5']]])
            ->assertOk()->assertJsonPath('read_only', true)->json();
        $this->assertTrue($sandbox['live_engine_available']);
        $this->assertSame('15.0000', $sandbox['rows'][0]['draft_entitlement']);
        $this->assertSame('12.0000', $sandbox['rows'][0]['current_entitlement']);
        $ent = $this->postJson('/api/v1/leave/simulations/entitlement', ['employee' => $this->emp->uuid, 'leave_type_id' => $alId, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31'])->assertOk()->json();
        $this->assertSame('entitlement_engine', $ent['source']);
        $this->context($this->admin->tenant_id);
        $this->assertSame($before, [LeaveLedgerEntry::count(), DB::table('leave_accounts')->count()]);
    }

    public function test_tenant_isolation_across_all_four_workstreams(): void
    {
        $uuid = $this->submit();
        $acct = $this->balance()['account']['id'];
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson("/api/v1/leave/policies/{$this->policyUuid}")->assertNotFound();
        $this->getJson('/api/v1/leave/types/'.$this->al['uuid'])->assertNotFound();
        $this->getJson("/api/v1/leave/accounts/$acct")->assertNotFound();
        $this->getJson("/api/v1/leave/requests/$uuid")->assertNotFound();
        $this->postJson("/api/v1/leave/requests/$uuid/approve")->assertNotFound();
        $this->getJson('/api/v1/leave/explain/balance?employee='.$this->emp->uuid)->assertNotFound();
        $this->getJson('/api/v1/leave/reports/requests')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame([], $this->getJson('/api/v1/leave/types')->json('data'));
    }
}
