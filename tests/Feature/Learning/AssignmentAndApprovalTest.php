<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Models\{Enrolment, EmployeeCert};
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;

class AssignmentAndApprovalTest extends LearningTestCase
{
    public function test_hr_assigns_course_to_employees_and_group_with_due_date_and_skips_duplicates(): void
    {
        $c = $this->course('SAFE-1');
        $r = $this->assign($c, [$this->emp1, $this->emp2], ['due_on' => '2026-11-30'])->assertCreated();
        $this->assertCount(2, $r->json('assigned'));
        $this->assertSame('2026-11-30', $this->enrolmentOf($this->emp1, $c)->due_on->format('Y-m-d'));
        $this->assertSame('enrolled', $this->enrolmentOf($this->emp1, $c)->status);

        $again = $this->assign($c, [$this->emp1, $this->outsider])->assertCreated();
        $this->assertCount(1, $again->json('assigned'));
        $this->assertSame('Already enrolled or completed.', $again->json('skipped.0.reason'));

        $this->context($this->admin->tenant_id);
        $gid = DB::table('employee_groups')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'name' => 'Ops', 'code' => 'OPS', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $c2 = $this->course('SAFE-2');
        $this->context($this->admin->tenant_id);
        DB::table('employee_group_members')->insert(['tenant_id' => $this->admin->tenant_id, 'group_id' => $gid, 'employee_id' => $this->emp2->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->signIn($this->admin);
        $this->postJson($this->p('assign/course'), ['course_uuid' => $c2, 'group_id' => $gid, 'due_on' => '2026-12-01'])->assertCreated()->assertJsonCount(1, 'assigned');
        $this->postJson($this->p('assign/course'), ['course_uuid' => $c2, 'employee_uuids' => [$this->emp1->uuid], 'due_on' => '2026-01-01'])->assertUnprocessable();
    }

    public function test_assigning_a_path_enrols_each_published_course(): void
    {
        $a = $this->course('P-A'); $b = $this->course('P-B');
        $path = $this->postJson($this->p('paths'), ['name' => 'Onboarding', 'items' => [['course_uuid' => $a], ['course_uuid' => $b]]])->assertCreated()->json('uuid');
        $this->context($this->admin->tenant_id);
        \App\Domain\Learning\Models\Path::where('uuid', $path)->update(['status' => 'published']);
        $this->signIn($this->admin);
        $r = $this->postJson($this->p('assign/path'), ['path_uuid' => $path, 'employee_uuids' => [$this->emp1->uuid], 'due_on' => '2026-12-31'])->assertCreated();
        $this->assertCount(2, $r->json('assigned'));
    }

    public function test_manager_is_limited_to_their_team_and_employees_cannot_assign(): void
    {
        $c = $this->course('SCOPE-1');
        $this->actAs($this->mgr);
        $this->assign($c, [$this->emp1], ['due_on' => '2026-12-31'])->assertCreated()->assertJsonCount(1, 'assigned');
        $this->assign($c, [$this->outsider])->assertNotFound(); // outside the manager's data scope
        $this->actAs($this->emp1);
        $this->assign($c, [$this->emp2])->assertForbidden();
        $this->getJson($this->p('enrolments'))->assertForbidden();
    }

    public function test_session_capacity_and_waitlist_still_apply_to_assignments(): void
    {
        $c = $this->course('CAP-1');
        $s = $this->makeSession($c, 'CAP-S1', '2026-11-02', '2026-11-03', ['capacity' => 1]);
        $r = $this->assign($c, [$this->emp1, $this->emp2], ['session_uuid' => $s])->assertCreated();
        $this->assertCount(2, $r->json('assigned'));
        $this->assertSame('enrolled', $this->enrolmentOf($this->emp1, $c)->status);
        $this->assertSame('waitlisted', $this->enrolmentOf($this->emp2, $c)->status);
    }

    public function test_approval_required_enrolment_routes_to_manager_my_work_and_is_decided_once_with_maker_checker(): void
    {
        $c = $this->course('APPR-1', ['requires_approval' => true]);
        $this->actAs($this->emp1);
        $uuid = $this->postJson($this->p("courses/$c/enrol"), [])->assertCreated()->assertJsonPath('status', 'pending_approval')->json('uuid');

        $this->context($this->admin->tenant_id);
        $mgrUser = $this->userOf($this->mgr);
        $this->assertSame(1, WorkItem::where('assignee_id', $mgrUser->id)->where('source_module', 'learning')->where('source_entity', 'enrolment:'.$uuid)->where('status', 'open')->count());

        // The employee cannot decide; the outsider's manager chain does not include the approver either.
        $this->actAs($this->emp1);
        $this->postJson($this->p("enrolments/$uuid/decision"), ['decision' => 'approve'])->assertForbidden();

        // Rejection needs a reason.
        $this->actAs($this->mgr);
        $this->postJson($this->p("enrolments/$uuid/decision"), ['decision' => 'reject'])->assertUnprocessable();
        $this->postJson($this->p("enrolments/$uuid/decision"), ['decision' => 'approve', 'note' => 'ok'])->assertOk()->assertJsonPath('status', 'enrolled');
        $this->postJson($this->p("enrolments/$uuid/decision"), ['decision' => 'approve'])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->assertSame(0, WorkItem::where('source_entity', 'enrolment:'.$uuid)->where('status', 'open')->count());
    }

    public function test_requester_cannot_approve_own_request_even_with_approval_permission(): void
    {
        $c = $this->course('APPR-2', ['requires_approval' => true]);
        $this->actAs($this->mgr);
        $uuid = $this->postJson($this->p("courses/$c/enrol"), [])->assertCreated()->assertJsonPath('status', 'pending_approval')->json('uuid');
        $this->postJson($this->p("enrolments/$uuid/decision"), ['decision' => 'approve'])->assertStatus(403);
        $this->signIn($this->admin);
        $this->postJson($this->p("enrolments/$uuid/decision"), ['decision' => 'reject', 'note' => 'Not this quarter'])->assertOk()->assertJsonPath('status', 'rejected');
    }

    public function test_attendance_and_completion_enforce_minimum_attendance_then_grant_skill_and_certificate(): void
    {
        $this->signIn($this->admin);
        $skill = $this->postJson($this->p('skills'), ['name' => 'Fire safety'])->assertCreated()->json('uuid');
        $cert = $this->postJson($this->p('certifications'), ['code' => 'FIRE', 'name' => 'Fire Warden', 'validity_months' => 12])->assertCreated();
        $c = $this->course('FIRE-1', ['min_attendance_pct' => 100, 'certification_code' => 'FIRE', 'skills' => [['skill_uuid' => $skill, 'grants_rank' => 2]]]);
        $s = $this->makeSession($c, 'FIRE-S1', '2026-10-08', '2026-10-09');
        $this->assign($c, [$this->emp1], ['session_uuid' => $s, 'due_on' => '2026-10-30'])->assertCreated();
        $e = $this->enrolmentOf($this->emp1, $c)->uuid;

        $this->signIn($this->admin);
        $this->postJson($this->p("enrolments/$e/attendance"), ['session_date' => '2026-10-20', 'present' => true])->assertUnprocessable();
        $this->postJson($this->p("enrolments/$e/attendance"), ['session_date' => '2026-10-08', 'present' => true])->assertOk()->assertJsonPath('status', 'in_progress');
        $this->postJson($this->p("enrolments/$e/complete"), ['score' => 90])->assertUnprocessable();
        $this->postJson($this->p("enrolments/$e/attendance"), ['session_date' => '2026-10-09', 'present' => true])->assertOk();
        $this->postJson($this->p("enrolments/$e/complete"), ['score' => 90])->assertOk()->assertJsonPath('status', 'completed');

        $this->context($this->admin->tenant_id);
        $ec = EmployeeCert::where('employee_id', $this->emp1->id)->firstOrFail();
        $this->assertSame('2027-10-07', $ec->expires_on->format('Y-m-d'));
        $this->assertDatabaseHas('employee_skills', ['person_id' => $this->emp1->person_id, 'level_rank' => 2, 'source' => 'training']);
        // A second completion call cannot re-issue.
        $this->postJson($this->p("enrolments/$e/complete"), [])->assertUnprocessable();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, EmployeeCert::where('employee_id', $this->emp1->id)->count());
    }

    public function test_cannot_record_own_completion_and_failed_score_fails_the_enrolment(): void
    {
        $c = $this->course('PASS-1', ['pass_percent' => 70]);
        $this->assign($c, [$this->emp1, $this->emp2])->assertCreated();
        $e1 = $this->enrolmentOf($this->emp1, $c)->uuid;
        $e2 = $this->enrolmentOf($this->emp2, $c)->uuid;
        $this->signIn($this->admin);
        $this->postJson($this->p("enrolments/$e1/complete"), ['score' => 40])->assertOk()->assertJsonPath('status', 'failed');
        $this->postJson($this->p("enrolments/$e2/complete"), ['score' => 80])->assertOk()->assertJsonPath('status', 'completed');
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, Enrolment::count());
    }
}
