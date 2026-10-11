<?php

namespace Tests\Feature\Learning;

use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Learning\Models\Enrolment;
use App\Domain\Learning\Operations\LearningEmployee360Provider;

class SkillsReportsAndIsolationTest extends LearningTestCase
{
    public function test_taxonomy_admin_requirements_validation_with_evidence_and_gap_view(): void
    {
        $this->signIn($this->admin);
        $cat = $this->postJson($this->p('skill-categories'), ['code' => 'TECH', 'name' => 'Technical'])->assertCreated()->json('uuid');
        $this->postJson($this->p('skill-categories'), ['code' => 'TECH', 'name' => 'Dup'])->assertUnprocessable();
        $skill = $this->postJson($this->p('skills'), ['name' => 'SQL', 'category_uuid' => $cat])->assertCreated()->assertJsonPath('category.name', 'Technical')->json('uuid');
        $this->putJson($this->p('skill-levels'), ['levels' => [['rank' => 1, 'name' => 'Basic'], ['rank' => 2, 'name' => 'Working'], ['rank' => 3, 'name' => 'Expert']]])->assertOk()->assertJsonCount(3);
        $this->putJson($this->p('skill-requirements'), ['designation_id' => $this->org['designation'][0], 'skill_uuid' => $skill, 'required_rank' => 3])->assertOk()->assertJsonPath('required_rank', 3);
        $this->putJson($this->p('skill-requirements'), ['designation_id' => $this->org['designation'][0], 'skill_uuid' => $skill, 'required_rank' => 4])->assertUnprocessable();

        // Employees cannot administer the taxonomy.
        $this->actAs($this->emp1);
        $this->postJson($this->p('skills'), ['name' => 'Nope'])->assertForbidden();
        $this->postJson($this->p('my/skills'), ['skill_uuid' => $skill, 'level' => 1])->assertOk();

        // The manager validates with evidence; evidence text is mandatory.
        $this->actAs($this->mgr);
        $this->postJson($this->p("employees/{$this->emp1->uuid}/skills/$skill/validate"), ['level' => 2])->assertUnprocessable();
        $this->postJson($this->p("employees/{$this->emp1->uuid}/skills/$skill/validate"), ['level' => 2, 'evidence' => ['note' => 'x']])->assertUnprocessable();
        $this->postJson($this->p("employees/{$this->emp1->uuid}/skills/$skill/validate"), ['level' => 2, 'evidence' => ['note' => 'Reviewed the reporting queries', 'kind' => 'project']])->assertOk()
            ->assertJsonPath('skills.0.validated', true)->assertJsonPath('skills.0.evidence_count', 1);
        $this->getJson($this->p("employees/{$this->emp1->uuid}/skills/$skill/evidence"))->assertOk()->assertJsonCount(1);
        $this->postJson($this->p("employees/{$this->outsider->uuid}/skills/$skill/validate"), ['level' => 2, 'evidence' => ['note' => 'Some evidence']])->assertNotFound();
        $this->postJson($this->p("employees/{$this->mgr->uuid}/skills/$skill/validate"), ['level' => 2, 'evidence' => ['note' => 'Self assessment']])->assertStatus(403);

        $gaps = $this->getJson($this->p('skill-gaps'))->assertOk()->json();
        $row = collect($gaps)->firstWhere('skill_uuid', $skill);
        $this->assertSame(3, $row['people']); // the manager's scope: two reports plus the manager
        $this->assertSame(3, $row['below']);
        $this->getJson($this->p("employees/{$this->emp2->uuid}/skill-gap"))->assertOk()->assertJsonPath('gap_count', 1);
        $this->getJson($this->p("employees/{$this->outsider->uuid}/skill-gap"))->assertNotFound();
    }

    public function test_dashboard_costs_are_decimal_safe_and_scoped_and_export_is_permissioned(): void
    {
        $c = $this->course('COST-1', ['cost' => '0.10']);
        $d = $this->course('COST-2', ['cost' => '0.20']);
        $this->assign($c, [$this->emp1, $this->outsider])->assertCreated();
        $this->assign($d, [$this->emp1])->assertCreated();

        $this->actAs($this->mgr);
        $dash = $this->getJson($this->p('dashboard'))->assertOk();
        $this->assertSame('0.30', $dash->json('cost.INR'));   // manager's team only: emp1 (0.10 + 0.20), not the outsider
        $this->assertSame('team', $dash->json('scope'));
        $this->getJson($this->p('command-center'))->assertForbidden();
        $this->getJson($this->p('reports/export'))->assertForbidden();

        $this->signIn($this->admin);
        $cc = $this->getJson($this->p('command-center'))->assertOk();
        $this->assertSame('0.40', $cc->json('cost.INR'));
        $this->assertSame('tenant', $cc->json('scope'));
        $csv = $this->get($this->p('reports/export'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Arun Kumar', $csv);
        $this->assertStringContainsString('0.10', $csv);

        $this->actAs($this->emp1);
        $this->getJson($this->p('dashboard'))->assertForbidden();
    }

    public function test_employee_360_learning_section_is_scoped(): void
    {
        $c = $this->course('E360-1');
        $this->assign($c, [$this->emp1], ['due_on' => '2026-12-01'])->assertCreated();
        $this->context($this->admin->tenant_id);
        $p = app(LearningEmployee360Provider::class);
        $sections = $p->sections($this->admin, $this->emp1, ['self' => false, 'manager' => false]);
        $this->assertSame('learning', $sections[0]['key']);
        $this->assertSame([], $p->sections($this->userOf($this->emp2), $this->emp1, ['self' => false, 'manager' => false]));
        $this->assertNotEmpty($p->sections($this->userOf($this->emp1), $this->emp1, ['self' => true, 'manager' => false]));
    }

    public function test_tenant_isolation_for_learning_records(): void
    {
        $c = $this->course('ISO-1', ['cost' => '5']);
        $this->assign($c, [$this->emp1])->assertCreated();
        $e = $this->enrolmentOf($this->emp1, $c)->uuid;
        $this->signIn($this->admin);
        $cat = $this->postJson($this->p('skill-categories'), ['code' => 'ISO', 'name' => 'Iso'])->assertCreated()->json('uuid');

        $other = app(WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($other);
        $this->getJson($this->p('courses'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->p('enrolments'))->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->p("enrolments/$e/complete"), [])->assertNotFound();
        $this->postJson($this->p("enrolments/$e/decision"), ['decision' => 'approve'])->assertNotFound();
        $this->postJson($this->p("enrolments/$e/attendance"), ['session_date' => '2026-10-08', 'present' => true])->assertNotFound();
        $this->patchJson($this->p("skill-categories/$cat"), ['name' => 'Hacked'])->assertNotFound();
        $this->postJson($this->p('assign/course'), ['course_uuid' => $c, 'employee_uuids' => [$this->emp1->uuid]])->assertNotFound();
        $this->assertSame(0, $this->getJson($this->p('dashboard'))->assertOk()->json('people')); // no people of the first workspace
        $this->context($other->tenant_id);
        $this->assertSame(0, Enrolment::count());
    }

    public function test_team_members_have_no_admin_access(): void
    {
        $c = $this->course('AUTH-1');
        $this->actAs($this->emp1);
        foreach (['enrolments', 'compliance/rules', 'compliance/status', 'certificates', 'skill-gaps', 'dashboard', 'command-center', 'lookups', 'skill-requirements'] as $path) {
            $this->getJson($this->p($path))->assertForbidden();
        }
        $this->postJson($this->p('compliance/rules'), ['name' => 'x', 'course_uuid' => $c, 'due_days' => 5])->assertForbidden();
        $this->postJson($this->p('compliance/run'))->assertForbidden();
        $this->postJson($this->p('certifications'), ['code' => 'A', 'name' => 'A'])->assertForbidden();
        $this->putJson($this->p('skill-levels'), ['levels' => [['rank' => 1, 'name' => 'a'], ['rank' => 2, 'name' => 'b']]])->assertForbidden();
    }
}
