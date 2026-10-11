<?php

namespace Tests\Feature\Recruitment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RequisitionVersionTest extends TestCase
{
    use PipelineFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRecruitment();
    }

    private function amend(string $req, array $changes, string $reason = 'Business need changed')
    {
        return $this->postJson('/api/v1/recruitment/requisitions/'.$req.'/amend', $changes + ['reason' => $reason]);
    }

    private function row(string $req): object
    {
        $this->context($this->admin->tenant_id);

        return DB::table('recruitment_requisitions')->where('uuid', $req)->first();
    }

    public function test_non_increasing_changes_apply_immediately_as_a_new_version(): void
    {
        $req = $this->requisition(3);
        $res = $this->amend($req, ['openings' => 2, 'budget_amount' => '900.00', 'target_date' => '2026-12-15'])->assertCreated()
            ->assertJsonPath('version', 2)->assertJsonPath('status', 'applied')->assertJsonPath('requires_approval', false)
            ->assertJsonPath('before.openings', 3)->assertJsonPath('after.openings', 2)->assertJsonPath('before.budget_amount', '1000.00')->assertJsonPath('after.budget_amount', '900.00');
        $this->assertEqualsCanonicalizing(['openings', 'budget_amount', 'target_date'], $res->json('changed_fields'));
        $row = $this->row($req);
        $this->assertSame(2, (int) $row->openings);
        $this->assertSame(2, (int) $row->version);
        $this->assertSame('open', $row->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'requisition.amended', 'resource' => $req]);
        $this->amend($req, [])->assertUnprocessable(); // nothing changed
        $this->amend($req, ['openings' => 2])->assertUnprocessable();
    }

    public function test_increases_wait_for_independent_approval_and_leave_work_untouched(): void
    {
        $req = $this->requisition(2);
        $app = $this->apply($req);
        $this->context($this->admin->tenant_id);
        DB::table('recruitment_requisitions')->where('uuid', $req)->update(['filled_count' => 1]);

        $res = $this->amend($req, ['openings' => 5])->assertCreated()->assertJsonPath('status', 'pending')->assertJsonPath('requires_approval', true);
        $row = $this->row($req);
        $this->assertSame(2, (int) $row->openings); // live requisition unchanged until approved
        $this->assertSame(1, (int) $row->version);
        $this->assertSame(1, (int) $row->filled_count);
        $this->amend($req, ['openings' => 6])->assertConflict(); // one pending amendment at a time
        $this->postJson('/api/v1/recruitment/requisitions/'.$req.'/versions/2/decision', ['decision' => 'approved'])->assertForbidden(); // maker cannot check
        $this->move($app, 'applied', 'screening')->assertOk(); // open applications keep flowing

        $this->signIn($this->checker)->postJson('/api/v1/recruitment/requisitions/'.$req.'/versions/2/decision', ['decision' => 'approved'])->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/v1/recruitment/requisitions/'.$req.'/versions/2/decision', ['decision' => 'approved'])->assertConflict();
        $row = $this->row($req);
        $this->assertSame(5, (int) $row->openings);
        $this->assertSame(2, (int) $row->version);
        $this->assertSame(1, (int) $row->filled_count); // headcount counters preserved
        $this->assertSame('open', $row->status);
        $this->assertSame(1, DB::table('recruitment_applications')->where('requisition_id', $row->id)->where('stage', 'screening')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'requisition.amendment_approved', 'resource' => $req]);
    }

    public function test_budget_increase_needs_approval_and_rejection_keeps_original(): void
    {
        $req = $this->requisition(1);
        $this->amend($req, ['budget_amount' => '1000.01'])->assertCreated()->assertJsonPath('status', 'pending');
        $this->signIn($this->checker)->postJson('/api/v1/recruitment/requisitions/'.$req.'/versions/2/decision', ['decision' => 'rejected'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame('1000.00', number_format((float) $this->row($req)->budget_amount, 2, '.', ''));
        $this->assertSame(1, (int) $this->row($req)->version);
        $this->signIn($this->admin);
        $this->amend($req, ['budget_amount' => '1000.00', 'department_id' => $this->org['department'][0]])->assertUnprocessable(); // unchanged
        $this->amend($req, ['budget_amount' => '500.5'])->assertCreated()->assertJsonPath('status', 'applied')->assertJsonPath('version', 3); // decrease applies directly
        $this->assertSame('500.50', number_format((float) $this->row($req)->budget_amount, 2, '.', ''));
        // After a decrease the approved amount is the lower one; going back up needs approval again.
        $this->amend($req, ['budget_amount' => '900'])->assertCreated()->assertJsonPath('status', 'pending');
    }

    public function test_openings_cannot_drop_below_filled_and_closed_requisitions_cannot_be_amended(): void
    {
        $req = $this->requisition(3);
        $this->context($this->admin->tenant_id);
        DB::table('recruitment_requisitions')->where('uuid', $req)->update(['filled_count' => 2]);
        $this->amend($req, ['openings' => 1])->assertUnprocessable();
        $this->amend($req, ['openings' => 2, 'expected_version' => 9])->assertConflict();
        $this->amend($req, ['openings' => 2, 'expected_version' => 1])->assertCreated();
        $this->postJson('/api/v1/recruitment/requisitions/'.$req.'/close')->assertOk();
        $this->amend($req, ['openings' => 1])->assertConflict();
    }

    public function test_pending_requisition_amendment_applies_without_reapproval_and_history_lists_versions(): void
    {
        $req = $this->requisition(1, [], false);
        $this->amend($req, ['openings' => 4])->assertCreated()->assertJsonPath('status', 'applied'); // still awaiting its initial approval
        $this->amend($req, ['openings' => 5, 'target_date' => '2027-01-10'])->assertCreated()->assertJsonPath('version', 3);
        $history = $this->getJson('/api/v1/recruitment/requisitions/'.$req.'/versions')->assertOk();
        $this->assertSame([3, 2], array_column($history->json('data'), 'version'));
        $this->assertSame('Business need changed', $history->json('data.0.reason'));
        $this->assertSame($this->admin->id, $history->json('data.0.created_by'));
        $this->assertSame(4, $history->json('data.0.before.openings'));
        $this->assertSame(5, $history->json('data.0.after.openings'));
        $this->getJson('/api/v1/recruitment/requisitions')->assertOk()->assertJsonPath('data.0.version', 3);
    }

    public function test_authorization_scope_and_tenant_isolation(): void
    {
        $req = $this->requisition(1);
        $this->amend($req, ['openings' => 3])->assertCreated();
        $this->context($this->admin->tenant_id);
        $member = $this->userWithRole('Team member', 'member@alpha.test');
        $this->signIn($member);
        $this->amend($req, ['openings' => 2])->assertForbidden();
        $this->getJson('/api/v1/recruitment/requisitions/'.$req.'/versions')->assertForbidden();
        $this->postJson('/api/v1/recruitment/requisitions/'.$req.'/versions/2/decision', ['decision' => 'approved'])->assertForbidden();

        $other = $this->bootTenant('beta');
        $this->signIn($other);
        $this->amend($req, ['openings' => 2])->assertNotFound();
        $this->getJson('/api/v1/recruitment/requisitions/'.$req.'/versions')->assertNotFound();
        $this->postJson('/api/v1/recruitment/requisitions/'.$req.'/versions/2/decision', ['decision' => 'approved'])->assertNotFound();
    }
}
