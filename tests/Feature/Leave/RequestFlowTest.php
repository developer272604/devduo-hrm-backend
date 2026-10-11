<?php

namespace Tests\Feature\Leave;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Requests\DecisionService;
use App\Domain\Leave\Requests\RequestService;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RequestFlowTest extends TestCase
{
    use RefreshDatabase, RequestFixtures;

    private $acct;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLeave();
        $this->acct = $this->account($this->emp, $this->annual, '10');
    }

    private function submit(array $o = [], $as = null): array
    {
        return $this->as($as ?? $this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody($o))->json();
    }

    public function test_preview_writes_nothing_and_shows_projection_route_and_team_impact(): void
    {
        $res = $this->as($this->emp)->postJson('/api/v1/leave/requests/preview', $this->leaveBody())->assertOk()->json('data');
        $this->assertTrue($res['can_submit']);
        $this->assertSame('2.0000', $res['duration']['charged_units']);
        $this->assertSame('8.0000', $res['balance']['accounts'][0]['projected']);
        $this->assertSame(['manager'], $res['approval']['roles']);
        $this->assertSame(0, LeaveRequest::count());
        $this->assertSame('10.0000', $this->available($this->acct));
    }

    public function test_submit_reserves_approve_commits_and_attendance_sees_the_leave(): void
    {
        $data = $this->submit(['Idempotency-Key' => 'x']);
        $uuid = $data['data']['uuid'];
        $this->assertSame('pending', $data['data']['status']);
        $this->assertSame('8.0000', $this->available($this->acct));
        $this->assertSame(1, WorkItem::where('source_module', 'leave')->where('assignee_id', $this->user($this->boss)->id)->count());
        $this->assertNull(app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-12'), 'pending leave does not touch attendance');

        $this->as($this->emp)->postJson("/api/v1/leave/requests/$uuid/approve")->assertForbidden();
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve", ['comment' => 'Enjoy'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('8.0000', $this->available($this->acct));
        $this->assertSame(0, WorkItem::where('source_module', 'leave')->where('status', 'open')->count());
        $cov = app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-12');
        $this->assertSame('full_day', $cov['portion']);
        $this->assertTrue($cov['paid']);
        $detail = $this->as($this->emp)->getJson("/api/v1/leave/requests/$uuid")->assertOk()->json('data');
        $this->assertContains('approve', array_column($detail['history'], 'action'));
        $this->assertNotEmpty($detail['ledger']);
        $this->assertNotEmpty(collect($detail['ledger'])->firstWhere('type', 'consumption')['sources'], 'source-to-use trace');
        $this->assertSame('scheduled', collect($detail['ledger'])->firstWhere('type', 'consumption')['bucket']);
    }

    public function test_reject_withdraw_and_idempotent_submit_release_everything(): void
    {
        $key = ['Idempotency-Key' => 'k1'];
        $a = $this->as($this->emp)->withHeaders($key)->postJson('/api/v1/leave/requests', $this->leaveBody())->assertCreated()->json('data.uuid');
        $b = $this->as($this->emp)->withHeaders($key)->postJson('/api/v1/leave/requests', $this->leaveBody())->assertOk()->json();
        $this->assertSame($a, $b['data']['uuid']);
        $this->assertTrue($b['duplicate']);
        $this->assertSame(1, LeaveRequest::count());
        $this->assertSame('8.0000', $this->available($this->acct));

        $this->as($this->boss)->postJson("/api/v1/leave/requests/$a/reject", [])->assertStatus(422);
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$a/reject", ['comment' => 'Release freeze'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('10.0000', $this->available($this->acct));

        $this->flushHeaders();
        $c = $this->submit()['data']['uuid'];
        $this->as($this->emp)->postJson("/api/v1/leave/requests/$c/withdraw")->assertOk()->assertJsonPath('data.status', 'withdrawn');
        $this->assertSame('10.0000', $this->available($this->acct));
    }

    public function test_stale_pending_request_expires_once_and_releases_its_reservation(): void
    {
        $uuid = $this->submit()['data']['uuid'];
        $request = LeaveRequest::where('uuid', $uuid)->firstOrFail();
        $this->travel(91)->days();

        $this->assertTrue(app(RequestService::class)->expireIfOverdue($request));
        $this->assertFalse(app(RequestService::class)->expireIfOverdue($request->refresh()), 'expiry is idempotent');
        $this->assertSame('expired', $request->refresh()->status);
        $this->assertEquals(0, $request->refresh()->getRawOriginal('charged_units'));
        $this->assertSame('10.0000', $this->available($this->acct));
        $this->assertSame(0, WorkItem::where('source_entity', 'leave:'.$uuid)->where('status', 'open')->count());
        $this->assertContains('expire', $request->actions()->pluck('action')->all());
    }

    public function test_cancel_and_partial_cancel_reverse_consumption_and_notify_attendance(): void
    {
        $uuid = $this->submit(['start_date' => '2026-10-12', 'end_date' => '2026-10-14'])['data']['uuid'];
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk();
        $this->assertSame('7.0000', $this->available($this->acct));
        $this->as($this->emp)->postJson("/api/v1/leave/requests/$uuid/cancel", ['dates' => ['2026-10-14']])->assertOk()->assertJsonPath('data.status', 'partially_cancelled')->assertJsonPath('data.charged_units', '2.0000');
        $this->assertSame('8.0000', $this->available($this->acct));
        $this->assertNull(app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-14'));
        $this->assertNotNull(app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-13'));
        $this->as($this->emp)->postJson("/api/v1/leave/requests/$uuid/cancel", [])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('10.0000', $this->available($this->acct));
        $this->assertNull(app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-12'));
    }

    public function test_overlap_insufficient_balance_and_no_account_are_refused_with_reasons(): void
    {
        $this->submit();
        $dup = $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['start_date' => '2026-10-13', 'end_date' => '2026-10-14']))->assertStatus(422)->json();
        $this->assertStringContainsString('already have leave', $dup['message']);
        $big = $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['start_date' => '2026-10-19', 'end_date' => '2026-10-30']))->assertStatus(422)->json();
        $this->assertStringContainsString('Not enough', $big['message']);
        $other = LeaveType::create(['name' => 'Study', 'code' => 'ST', 'unit' => 'day']);
        $this->as($this->emp)->postJson('/api/v1/leave/requests', $this->leaveBody(['leave_type' => $other->uuid]))->assertStatus(422);
        $this->assertSame(1, LeaveRequest::count());
    }

    public function test_visibility_is_scoped_peers_cannot_see_requests(): void
    {
        $uuid = $this->submit()['data']['uuid'];
        $this->as($this->peer)->getJson("/api/v1/leave/requests/$uuid")->assertNotFound();
        $this->as($this->boss)->getJson("/api/v1/leave/requests/$uuid")->assertOk();
        $this->as($this->boss)->getJson('/api/v1/leave/requests?scope=team')->assertOk()->assertJsonCount(1, 'data');
        $this->as($this->peer)->getJson('/api/v1/leave/requests?scope=team')->assertForbidden();
        $this->assertCount(0, $this->as($this->peer)->getJson('/api/v1/leave/requests')->json('data'));
    }

    public function test_multi_step_approval_and_hr_escalation_after_the_wait(): void
    {
        $this->policy(['approvals' => ['steps' => ['manager', 'hr'], 'escalate_after_hours' => 24]]);
        $uuid = $this->submit()['data']['uuid'];
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.current_step', 2);
        $this->assertSame(1, WorkItem::where('assignee_id', $this->user($this->hr)->id)->where('source_module', 'leave')->where('status', 'open')->count());
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertNotFound();
        $this->as($this->hr)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $second = $this->submit(['start_date' => '2026-10-19', 'end_date' => '2026-10-19'])['data']['uuid'];
        $this->travel(50)->hours();
        $this->assertTrue(app(DecisionService::class)->escalateIfOverdue(LeaveRequest::where('uuid', $second)->first()));
        $this->assertFalse(app(DecisionService::class)->escalateIfOverdue(LeaveRequest::where('uuid', $second)->first()), 'idempotent');
        $this->assertSame(1, WorkItem::where('assignee_id', $this->user($this->hr)->id)->where('source_entity', 'leave:'.$second)->count());
        $this->assertContains('escalate', LeaveRequest::where('uuid', $second)->first()->actions()->pluck('action')->all());
    }

    public function test_controlled_modification_replaces_the_original_only_when_approved(): void
    {
        $uuid = $this->submit()['data']['uuid'];
        $this->as($this->boss)->postJson("/api/v1/leave/requests/$uuid/approve")->assertOk();
        $new = $this->as($this->emp)->postJson("/api/v1/leave/requests/$uuid/modify", ['start_date' => '2026-10-12', 'end_date' => '2026-10-15'])->assertCreated()->json('data');
        $this->assertSame($uuid, $new['modification_of']);
        $this->assertSame('approved', LeaveRequest::where('uuid', $uuid)->first()->status);
        $this->as($this->boss)->postJson("/api/v1/leave/requests/{$new['uuid']}/approve")->assertOk();
        $this->assertSame('modified', LeaveRequest::where('uuid', $uuid)->first()->status);
        $this->assertSame('6.0000', $this->available($this->acct));
        $this->assertNull(app(LeaveDataProvider::class)->coverage($this->emp->id, '2026-10-16'));
    }

    public function test_medical_privacy_hides_type_reason_and_documents_from_managers(): void
    {
        Storage::fake('leave_private');
        $sick = LeaveType::create(['name' => 'Medical leave', 'code' => 'ML', 'kind' => 'medical', 'unit' => 'day', 'is_sensitive' => true, 'tracks_balance' => false]);
        $pdf = UploadedFile::fake()->createWithContent('note.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $res = $this->as($this->emp)->post('/api/v1/leave/requests', $this->leaveBody(['leave_type' => $sick->uuid, 'reason' => 'Surgery', 'documents' => [$pdf]]), ['Accept' => 'application/json']);
        $res->assertCreated();
        $uuid = $res->json('data.uuid');
        $this->assertSame('Surgery', $res->json('data.reason'));
        $mgr = $this->as($this->boss)->getJson("/api/v1/leave/requests/$uuid")->assertOk()->json('data');
        $this->assertSame('Leave', $mgr['type']['name']);
        $this->assertNull($mgr['reason']);
        $this->assertTrue($mgr['reason_hidden']);
        $this->assertSame([], $mgr['documents']);
        $this->assertSame(1, $mgr['documents_hidden']);
        $task = WorkItem::where('source_entity', 'leave:'.$uuid)->first();
        $this->assertStringNotContainsString('Medical', $task->title);
        $hr = $this->as($this->hr)->getJson("/api/v1/leave/requests/$uuid")->json('data');
        $this->assertSame('Medical leave', $hr['type']['name']);
        $docId = $hr['documents'][0]['uuid'];
        $this->as($this->boss)->get("/api/v1/leave/requests/$uuid/documents/$docId")->assertNotFound();
        $this->as($this->hr)->get("/api/v1/leave/requests/$uuid/documents/$docId")->assertOk();
        $this->as($this->boss)->getJson('/api/v1/leave/team/calendar?from=2026-10-01&to=2026-10-31')->assertOk()->assertJsonPath('data.0.type.name', 'Leave');
    }
}
