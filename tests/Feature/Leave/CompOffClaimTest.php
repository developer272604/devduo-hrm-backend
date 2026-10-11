<?php

namespace Tests\Feature\Leave;

use App\Domain\Attendance\Models\OvertimeRequest;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveCompOffCredit;
use App\Domain\Leave\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompOffClaimTest extends TestCase
{
    use RefreshDatabase, RequestFixtures;

    private LeaveType $comp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLeave();
        $this->comp = LeaveType::create(['name' => 'Comp-off', 'code' => 'CO', 'kind' => 'comp_off', 'unit' => 'half_day']);
        $this->account($this->emp, $this->comp, '0'); // accounts are opened by the entitlement workstream; the fake policy has no version to open one from
    }

    private function overtime(string $type = 'holiday', int $minutes = 480): OvertimeRequest
    {
        return OvertimeRequest::create(['employee_id' => $this->emp->id, 'work_date' => '2026-10-02', 'mode' => 'post_work', 'initiated_by' => 'employee', 'ot_type' => $type, 'requested_minutes' => $minutes,
            'approved_minutes' => $minutes, 'reason' => 'Release', 'status' => 'approved', 'requested_by' => $this->user($this->emp)->id]);
    }

    public function test_earn_approve_claim_and_trace_from_source_to_use_with_expiry(): void
    {
        $ot = $this->overtime();
        $credit = $this->as($this->emp)->postJson('/api/v1/leave/comp-off', ['source_type' => 'overtime', 'source_id' => $ot->uuid])->assertCreated()->json('data');
        $this->assertSame('pending', $credit['status']);
        $this->assertSame('1.0000', $credit['units']);
        $this->assertSame('holiday_work', $credit['source_type']);
        $this->as($this->emp)->postJson('/api/v1/leave/comp-off', ['source_type' => 'overtime', 'source_id' => $ot->uuid])->assertStatus(422);
        $this->as($this->emp)->postJson("/api/v1/leave/comp-off/{$credit['uuid']}/approve")->assertForbidden();

        $approved = $this->as($this->boss)->postJson("/api/v1/leave/comp-off/{$credit['uuid']}/approve")->assertOk()->json('data');
        $this->assertSame('approved', $approved['status']);
        $this->assertSame('2026-12-31', $approved['expires_on'], '90 days default validity from the work date');
        $account = LeaveAccount::where('employee_id', $this->emp->id)->where('leave_type_id', $this->comp->id)->first();
        $this->assertSame('1.0000', $this->available($account));

        $claim = $this->as($this->emp)->postJson("/api/v1/leave/comp-off/{$credit['uuid']}/claim", ['start_date' => '2026-10-12', 'end_date' => '2026-10-12'])->assertCreated()->json('data');
        $this->assertSame('pending', $claim['claim']['status']);
        $this->assertSame('0.0000', $this->available($account));
        $this->as($this->emp)->postJson("/api/v1/leave/comp-off/{$credit['uuid']}/claim", ['start_date' => '2026-10-14', 'end_date' => '2026-10-14'])->assertStatus(422);
        $this->as($this->boss)->postJson("/api/v1/leave/requests/{$claim['request']['uuid']}/approve")->assertOk();

        $trace = $this->as($this->emp)->getJson("/api/v1/leave/comp-off/{$credit['uuid']}/trace")->assertOk()->json('data');
        $this->assertSame('holiday_work', $trace['source']['type']);
        $this->assertCount(1, $trace['uses']);
        $this->assertSame($claim['request']['uuid'], $trace['uses'][0]['request']);
        $list = $this->as($this->emp)->getJson('/api/v1/leave/comp-off')->json('data.0');
        $this->assertSame('0.0000', $list['remaining']);
        $this->assertSame('approved', $list['claims'][0]['status']);
    }

    public function test_unapproved_overtime_cannot_earn_and_authorized_credit_needs_permission(): void
    {
        $ot = $this->overtime();
        $ot->update(['status' => 'pending']);
        $this->as($this->emp)->postJson('/api/v1/leave/comp-off', ['source_type' => 'overtime', 'source_id' => $ot->uuid])->assertStatus(422);
        $this->as($this->emp)->postJson('/api/v1/leave/comp-off', ['source_type' => 'authorized', 'work_date' => '2026-10-02', 'units' => '1'])->assertForbidden();
        $granted = $this->as($this->hr)->postJson('/api/v1/leave/comp-off', ['employee' => $this->emp->uuid, 'source_type' => 'authorized', 'work_date' => '2026-10-02', 'units' => '0.5', 'note' => 'Weekend support'])->assertCreated()->json('data');
        $this->assertSame('approved', $granted['status']);
        $this->assertSame(1, LeaveCompOffCredit::where('status', 'approved')->count());
    }

    public function test_expiry_marks_lapsed_credits(): void
    {
        $credit = $this->as($this->hr)->postJson('/api/v1/leave/comp-off', ['employee' => $this->emp->uuid, 'source_type' => 'authorized', 'work_date' => '2026-10-02', 'units' => '1'])->json('data');
        $this->travel(120)->days();
        $this->assertSame(1, app(\App\Domain\Leave\CompOff\CompOffService::class)->expireDue());
        $this->assertSame('expired', LeaveCompOffCredit::where('uuid', $credit['uuid'])->first()->status);
    }
}
