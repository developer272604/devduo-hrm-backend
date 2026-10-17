<?php

namespace Tests\Feature\Learning;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

class CareerNavigatorTest extends PerformanceTestCase
{
    private const URL = '/api/v1/learning/talent/my/career';

    private int $python;

    private int $sql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->python = $this->skill('Python');
        $this->sql = $this->skill('SQL');
    }

    private function skill(string $name): int
    {
        return DB::table('skills')->insertGetId(['tenant_id' => $this->admin->tenant_id, 'uuid' => (string) Str::uuid(), 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function require(int $designationIndex, int $skill, int $rank, bool $mandatory = true): void
    {
        DB::table('learning_skill_requirements')->insert(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'designation_id' => $this->org['designation'][$designationIndex], 'skill_id' => $skill, 'required_rank' => $rank, 'mandatory' => $mandatory, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function know(int $skill, int $rank, string $verification = 'unverified'): void
    {
        DB::table('employee_skills')->insert(['tenant_id' => $this->admin->tenant_id, 'person_id' => $this->emp1->person_id, 'skill_id' => $skill, 'proficiency' => 'intermediate', 'level_rank' => $rank,
            'verification_status' => $verification, 'source' => 'self', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function openOpportunity(int $skill, string $status = 'open'): void
    {
        $id = DB::table('talent_opportunities')->insertGetId(['uuid' => (string) Str::uuid(), 'tenant_id' => $this->admin->tenant_id, 'posted_by' => $this->admin->id, 'title' => 'Reporting revamp', 'kind' => 'project', 'hours_per_week' => 5,
            'starts_on' => '2026-10-20', 'openings' => 1, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('talent_opportunity_skills')->insert(['tenant_id' => $this->admin->tenant_id, 'opportunity_id' => $id, 'skill_id' => $skill, 'required_rank' => 2, 'mandatory' => true]);
    }

    public function test_roles_are_ranked_by_fit_with_gaps_and_the_current_role_is_excluded(): void
    {
        // emp1 is an Engineer (designation 0): requirement met, so it is not offered as a next step.
        $this->require(0, $this->python, 2);
        // Senior Engineer: needs Python 3 and SQL 2, both mandatory.
        $this->require(1, $this->python, 3);
        $this->require(1, $this->sql, 2);
        // Manager: needs Python 2 (mandatory) and SQL 1 (optional).
        $this->require(2, $this->python, 2);
        $this->require(2, $this->sql, 1, false);
        $this->know($this->python, 2);

        $this->openOpportunity($this->sql);
        $r = $this->actAs($this->emp1)->getJson(self::URL)->assertOk();
        $this->assertSame('Engineer', $r->json('current_designation'));
        $roles = $r->json('roles');
        $this->assertSame(['Manager', 'Senior Engineer'], array_column($roles, 'designation'));

        $this->assertTrue($roles[0]['ready_now']);
        $this->assertEquals(0.67, $roles[0]['readiness']); // (2x1.0 + 1x0) / 3
        $this->assertSame(['Python'], $roles[0]['skills_met']);
        $this->assertFalse($roles[0]['gaps'][0]['mandatory']);

        $this->assertFalse($roles[1]['ready_now']);
        $this->assertEquals(0.33, $roles[1]['readiness']); // (2x2/3 + 0) / 4
        $this->assertCount(2, $roles[1]['gaps']);
        $sql = collect($roles[1]['gaps'])->firstWhere('skill', 'SQL');
        $this->assertSame('none', $sql['current']);
        $this->assertSame('Reporting revamp', $sql['opportunities'][0]['title']); // an open opportunity that develops the missing skill
        $this->assertStringContainsString('not a promise', $r->json('limitations'));
    }

    public function test_rejected_skill_claims_and_closed_opportunities_do_not_count(): void
    {
        $this->require(1, $this->python, 2);
        $this->know($this->python, 4, 'rejected'); // a rejected claim counts as no skill
        $this->openOpportunity($this->python, 'closed');
        $role = $this->actAs($this->emp1)->getJson(self::URL)->assertOk()->json('roles.0');
        $this->assertSame('Senior Engineer', $role['designation']);
        $this->assertFalse($role['ready_now']);
        $this->assertSame('none', $role['gaps'][0]['current']);
        $this->assertSame([], $role['gaps'][0]['opportunities']);
    }

    public function test_roles_without_configured_requirements_are_not_listed(): void
    {
        $this->assertSame([], $this->actAs($this->emp1)->getJson(self::URL)->assertOk()->json('roles'));
    }

    public function test_it_is_self_service_for_working_employees_only(): void
    {
        $this->require(1, $this->python, 2);
        $this->signIn($this->checker); // an administrator without an employment record
        $this->getJson(self::URL)->assertStatus(422);
        $this->actAs($this->emp1)->getJson(self::URL)->assertOk()->assertJsonCount(1, 'roles');
        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson(self::URL)->assertStatus(422);
    }
}
