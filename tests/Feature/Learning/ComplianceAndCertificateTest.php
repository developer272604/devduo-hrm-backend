<?php

namespace Tests\Feature\Learning;

use App\Domain\Learning\Models\{Assignment, EmployeeCert, Enrolment};
use App\Models\{InAppNotification, WorkItem};
use Illuminate\Support\Facades\DB;

class ComplianceAndCertificateTest extends LearningTestCase
{
    private function rule(string $course, array $extra = []): string
    {
        $this->signIn($this->admin);

        return $this->postJson($this->p('compliance/rules'), $extra + ['name' => 'Annual safety', 'course_uuid' => $course, 'due_days' => 10, 'escalation_days' => 2])->assertCreated()->json('uuid');
    }

    public function test_rule_targets_by_country_and_assigns_idempotently(): void
    {
        $c = $this->course('COMP-1');
        $this->context($this->admin->tenant_id);
        DB::table('employees')->where('id', $this->emp2->id)->update(['country' => 'GB']);
        DB::table('employees')->where('id', '!=', $this->emp2->id)->update(['country' => 'IN']);
        $this->rule($c, ['country' => 'gb']);

        $this->artisan('learning:compliance-run')->assertSuccessful();
        $this->artisan('learning:compliance-run')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, Assignment::count());
        $a = Assignment::firstOrFail();
        $this->assertSame($this->emp2->id, (int) $a->employee_id);
        $this->assertSame('2026-10-17', $a->due_on->format('Y-m-d'));
        $this->assertSame(1, Enrolment::where('source', 'compliance')->count());
    }

    public function test_overdue_escalation_is_idempotent_and_reaches_my_work_of_employee_and_manager(): void
    {
        $c = $this->course('COMP-2');
        $this->rule($c, ['designation_id' => $this->org['designation'][0]]);
        $this->artisan('learning:compliance-run')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $total = Assignment::count();
        $this->assertGreaterThanOrEqual(3, $total);

        $this->travelTo(now()->addDays(14)); // due 2026-10-17, escalation after 2 days
        $this->artisan('learning:compliance-run')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $mgrUser = $this->userOf($this->mgr); $emp1User = $this->userOf($this->emp1);
        $tasks = WorkItem::where('source_module', 'learning')->count();
        $notes = InAppNotification::count();
        $this->assertSame(1, WorkItem::where('assignee_id', $emp1User->id)->where('source_entity', 'like', 'assignment:%')->where('status', 'open')->count());
        $this->assertGreaterThanOrEqual(2, WorkItem::where('assignee_id', $mgrUser->id)->where('source_entity', 'like', 'assignment-escalation:%')->count()); // emp1 + emp2 report to the manager

        $this->artisan('learning:compliance-run')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $this->assertSame($tasks, WorkItem::where('source_module', 'learning')->count());
        $this->assertSame($notes, InAppNotification::count());
        $this->assertSame($total, Assignment::count());

        // Manager sees team compliance; the outsider is outside the data scope.
        $this->actAs($this->mgr);
        $st = $this->getJson($this->p('compliance/status'))->assertOk();
        $names = collect($st->json('rows'))->pluck('employee.name')->all();
        $this->assertContains('Arun Kumar', $names);
        $this->assertNotContains('Omar Diaz', $names);
        $this->assertSame('overdue', collect($st->json('rows'))->firstWhere('employee.name', 'Arun Kumar')['status']);

        // Completing the course closes the assignment and its tasks.
        $e = $this->enrolmentOf($this->emp1, $c)->uuid;
        $this->signIn($this->admin);
        $this->postJson($this->p("enrolments/$e/complete"), [])->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame('completed', Assignment::where('employee_id', $this->emp1->id)->value('status'));
        $this->assertSame(0, WorkItem::where('assignee_id', $emp1User->id)->where('source_module', 'learning')->where('status', 'open')->count());
    }

    public function test_waiver_needs_reason_and_scope(): void
    {
        $c = $this->course('COMP-3');
        $this->rule($c, ['designation_id' => $this->org['designation'][0]]);
        $this->artisan('learning:compliance-run')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $uuid = Assignment::where('employee_id', $this->emp1->id)->value('uuid');
        $this->actAs($this->emp1);
        $this->postJson($this->p("compliance/assignments/$uuid/waive"), ['reason' => 'because'])->assertForbidden();
        $this->signIn($this->admin);
        $this->postJson($this->p("compliance/assignments/$uuid/waive"), ['reason' => 'x'])->assertUnprocessable();
        $this->postJson($this->p("compliance/assignments/$uuid/waive"), ['reason' => 'Exempt: on long leave'])->assertOk()->assertJsonPath('status', 'waived');
    }

    public function test_certificate_expiry_reminders_run_once_per_threshold_and_mark_expired(): void
    {
        $this->signIn($this->admin);
        $this->postJson($this->p('certifications'), ['code' => 'CPR', 'name' => 'CPR <b>Level</b>', 'validity_months' => 12, 'reminder_days' => [30, 7]])->assertCreated();
        $c = $this->course('CPR-1', ['certification_code' => 'CPR']);
        $this->assign($c, [$this->emp1])->assertCreated();
        $e = $this->enrolmentOf($this->emp1, $c)->uuid;
        $this->signIn($this->admin);
        $this->postJson($this->p("enrolments/$e/complete"), [])->assertOk();

        $this->context($this->admin->tenant_id);
        $cert = EmployeeCert::firstOrFail();
        $uid = $this->userOf($this->emp1)->id;
        $notes = fn () => InAppNotification::where('user_id', $uid)->where('title', 'like', '%certification%')->count();
        $this->artisan('learning:certificate-reminders')->assertSuccessful();
        $this->assertSame(0, $notes()); // 365 days away

        $cert->update(['expires_on' => '2026-10-27']); // 20 days
        $this->artisan('learning:certificate-reminders')->assertSuccessful();
        $this->artisan('learning:certificate-reminders')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, $notes());
        $this->assertSame(30, (int) $cert->refresh()->last_reminder_days);
        $this->assertSame(1, WorkItem::where('assignee_id', $uid)->where('source_entity', 'cert-renewal:'.$cert->uuid)->count());

        $cert->update(['expires_on' => '2026-10-12']); // 5 days -> next threshold 7
        $this->artisan('learning:certificate-reminders')->assertSuccessful();
        $this->artisan('learning:certificate-reminders')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, $notes());
        $this->assertSame(7, (int) $cert->refresh()->last_reminder_days);

        $cert->update(['expires_on' => '2026-10-01']);
        $this->artisan('learning:certificate-reminders')->assertSuccessful();
        $this->artisan('learning:certificate-reminders')->assertSuccessful();
        $this->context($this->admin->tenant_id);
        $this->assertSame('expired', $cert->refresh()->status);
        $this->assertSame(3, $notes());

        // Re-completion in a new enrolment renews: the old certificate is superseded.
        $e2 = Enrolment::create(['course_id' => Enrolment::where('uuid', $e)->value('course_id'), 'employee_id' => $this->emp1->id, 'status' => 'enrolled', 'source' => 'assigned', 'course_version' => 1, 'cost' => 0, 'currency' => 'INR']);
        $this->signIn($this->admin);
        $this->postJson($this->p("enrolments/{$e2->uuid}/complete"), [])->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame('renewed', $cert->refresh()->status);
        $this->assertSame(2, EmployeeCert::count());
    }

    public function test_printable_certificate_is_escaped_and_scoped(): void
    {
        $this->signIn($this->admin);
        $this->postJson($this->p('certifications'), ['code' => 'XSS', 'name' => '<script>alert(1)</script>', 'validity_months' => 6])->assertCreated();
        $c = $this->course('XSS-1', ['certification_code' => 'XSS']);
        $this->assign($c, [$this->emp1])->assertCreated();
        $e = $this->enrolmentOf($this->emp1, $c)->uuid;
        $this->signIn($this->admin);
        $this->postJson($this->p("enrolments/$e/complete"), [])->assertOk();
        $this->context($this->admin->tenant_id);
        $uuid = EmployeeCert::firstOrFail()->uuid;

        $this->actAs($this->emp1);
        $html = $this->get($this->p("certificates/$uuid/print"))->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8')->getContent();
        $this->assertStringContainsString('Arun Kumar', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->getJson($this->p('my/certificates'))->assertOk()->assertJsonCount(1);
        $this->actAs($this->emp2);
        $this->get($this->p("certificates/$uuid/print"))->assertNotFound();
        $this->actAs($this->mgr);
        $this->get($this->p("certificates/$uuid/print"))->assertOk();
    }
}
