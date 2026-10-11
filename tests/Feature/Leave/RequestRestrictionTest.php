<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Models\LeaveConflict;
use App\Domain\Leave\Models\LeaveDocumentRule;
use App\Domain\Leave\Models\LeaveRestriction;
use App\Domain\Leave\Models\LeaveType;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestRestrictionTest extends TestCase
{
    use RefreshDatabase, RequestFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLeave();
        $this->account($this->emp, $this->annual, '10');
        $this->account($this->peer, $this->annual, '10');
    }

    private function restrict(array $o): LeaveRestriction
    {
        return LeaveRestriction::create($o + ['name' => 'Rule', 'action' => 'warn', 'target_type' => 'tenant', 'target_id' => '*']);
    }

    public function test_policy_max_consecutive_counts_adjacent_pending_requests_of_the_same_leave_type(): void
    {
        $this->policy(['limits' => ['max_consecutive' => 2]]);
        $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody())->assertCreated();

        $next = $this->leaveBody(['start_date' => '2026-10-14', 'end_date' => '2026-10-14']);
        $blocked = $this->as($this->emp)->postJson('/api/v1/leave/requests/preview', $next)->assertOk()->json('data');
        $this->assertFalse($blocked['can_submit']);
        $limit = collect($blocked['conflicts'])->firstWhere('kind', 'max_consecutive');
        $this->assertNotNull($limit);
        $this->assertSame(3, $limit['detail']['span']);
        $this->assertSame(1, $limit['detail']['adjacent_requests']);

        $afterGap = $this->leaveBody(['start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
        $allowed = $this->as($this->emp)->postJson('/api/v1/leave/requests/preview', $afterGap)->assertOk()->json('data');
        $this->assertTrue($allowed['can_submit']);
        $this->assertFalse(collect($allowed['conflicts'])->contains('kind', 'max_consecutive'));
    }

    public function test_consecutive_restriction_counts_adjacent_pending_requests(): void
    {
        $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody())->assertCreated();
        $this->restrict(['name' => 'Consecutive leave review', 'kind' => 'consecutive', 'action' => 'block', 'params' => ['max_days' => 2]]);

        $next = $this->leaveBody(['start_date' => '2026-10-14', 'end_date' => '2026-10-14']);
        $preview = $this->as($this->emp)->postJson('/api/v1/leave/requests/preview', $next)->assertOk()->json('data');
        $conflict = collect($preview['conflicts'])->firstWhere('kind', 'consecutive');
        $this->assertFalse($preview['can_submit']);
        $this->assertNotNull($conflict);
        $this->assertSame(3, $conflict['detail']['span']);
    }

    public function test_partial_cancellation_breaks_a_consecutive_chain_at_the_cancelled_date(): void
    {
        $uuid = $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['end_date' => '2026-10-14']))->assertCreated()->json('data.uuid');
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk();
        $this->as($this->emp)->postJson("/api/v1/leave/requests/$uuid/cancel", ['dates' => ['2026-10-13']])->assertOk()->assertJsonPath('data.status', 'partially_cancelled');
        $this->policy(['limits' => ['max_consecutive' => 2]]);

        $next = $this->leaveBody(['start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
        $preview = $this->as($this->emp)->postJson('/api/v1/leave/requests/preview', $next)->assertOk()->json('data');
        $this->assertTrue($preview['can_submit']);
        $this->assertFalse(collect($preview['conflicts'])->contains('kind', 'max_consecutive'));
    }

    public function test_blackout_blocks_ordinary_leave_but_escalates_statutory_leave_to_hr(): void
    {
        $this->restrict(['name' => 'Year-end close', 'kind' => 'blackout', 'action' => 'block', 'start_date' => '2026-10-12', 'end_date' => '2026-10-16', 'params' => ['reason' => 'audit']]);
        $res = $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody())->assertStatus(422)->json();
        $this->assertStringContainsString('Year-end close', $res['message']);
        $this->assertSame('blackout', $res['conflicts'][0]['kind']);

        $mat = LeaveType::create(['name' => 'Parental', 'code' => 'PL', 'kind' => 'parental', 'category' => 'statutory', 'is_statutory' => true, 'unit' => 'day']);
        $this->account($this->emp, $mat, '20');
        $uuid = $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['leave_type' => $mat->uuid]))->assertCreated()->json('data');
        $this->assertSame(['manager', 'hr'], $uuid['approval']['roles'], 'escalated: HR joins the approval');
        $this->assertSame('review_required', $uuid['confidence']);
        $this->assertSame('escalate', LeaveConflict::where('kind', 'blackout')->first()->severity);
    }

    public function test_staffing_and_concurrency_restrictions_warn_or_block_from_team_data(): void
    {
        $this->restrict(['name' => 'Max one away', 'kind' => 'max_concurrent', 'action' => 'warn', 'params' => ['max' => 1, 'group_by' => 'department']]);
        $this->as($this->peer)->postJson('/api/v1/leave/requests', $this->leaveBody())->assertCreated();
        $res = $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody())->assertCreated()->json('data');
        $this->assertTrue(collect($res['conflicts'])->contains(fn ($c) => $c['kind'] === 'staffing' && $c['severity'] === 'warning'));

        $this->restrict(['name' => 'Keep cover', 'kind' => 'min_staffing', 'action' => 'block', 'params' => ['min_available' => 100, 'group_by' => 'department']]);
        $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['start_date' => '2026-10-20', 'end_date' => '2026-10-20']))->assertStatus(422);
    }

    public function test_required_documents_block_or_allow_later_submission_and_can_be_satisfied(): void
    {
        $sick = LeaveType::create(['name' => 'Sick', 'code' => 'SK', 'kind' => 'sick', 'unit' => 'day', 'tracks_balance' => false]);
        LeaveDocumentRule::create(['leave_type_id' => $sick->id, 'required_after_units' => '2', 'document_kind' => 'medical_certificate', 'mandatory' => true, 'submit_within_days' => null]);
        $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['leave_type' => $sick->uuid, 'start_date' => '2026-10-12', 'end_date' => '2026-10-13']))->assertStatus(422);
        $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['leave_type' => $sick->uuid, 'start_date' => '2026-10-12', 'end_date' => '2026-10-12']))->assertCreated();

        LeaveDocumentRule::query()->update(['submit_within_days' => 3]);
        $uuid = $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['leave_type' => $sick->uuid, 'start_date' => '2026-10-19', 'end_date' => '2026-10-20']))->assertCreated()->json('data.uuid');
        $preview = $this->as($this->emp)->postJson('/api/v1/leave/requests/preview', $this->leaveBody(['leave_type' => $sick->uuid, 'start_date' => '2026-10-26', 'end_date' => '2026-10-27']))->json('data');
        $this->assertTrue($preview['can_submit']);
        $this->assertSame('medical_certificate', $preview['documents'][0]['kind']);
        $this->assertNotNull($uuid);
    }

    public function test_team_calendar_and_availability_respect_data_scope(): void
    {
        $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody())->assertCreated();
        $cal = $this->as($this->boss)->getJson('/api/v1/leave/team/calendar?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');
        $this->assertCount(1, $cal);
        $this->assertSame('pending', $cal[0]['status']);
        $this->assertSame('2026-10-12', $cal[0]['start_date']);
        $av = $this->as($this->boss)->getJson('/api/v1/leave/team/availability?date=2026-10-12')->assertOk()->json('data');
        $this->assertSame(1.0, (float) $av['away']);
        $this->assertSame('Asha Rao', $av['away_list'][0]['employee']['name']);
        $this->as($this->peer)->getJson('/api/v1/leave/team/calendar?from=2026-10-01&to=2026-10-31')->assertForbidden();
        $this->assertSame(0, WorkItem::where('assignee_id', $this->user($this->peer)->id)->count());
    }
}
