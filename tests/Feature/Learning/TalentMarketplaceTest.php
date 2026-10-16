<?php

namespace Tests\Feature\Learning;

use App\Domain\People\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Performance\PerformanceTestCase;

class TalentMarketplaceTest extends PerformanceTestCase
{
    private const T = '/api/v1/learning/talent/';

    private string $python;

    private string $sql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->python = $this->skill('Python');
        $this->sql = $this->skill('SQL');
    }

    private function skill(string $name): string
    {
        $uuid = (string) Str::uuid();
        DB::table('skills')->insert(['tenant_id' => $this->admin->tenant_id, 'uuid' => $uuid, 'name' => $name, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);

        return $uuid;
    }

    private function know(Employee $e, string $skillUuid, int $rank, string $verification = 'unverified'): void
    {
        DB::table('employee_skills')->insert(['tenant_id' => $this->admin->tenant_id, 'person_id' => $e->person_id, 'skill_id' => DB::table('skills')->where('uuid', $skillUuid)->value('id'),
            'proficiency' => 'intermediate', 'level_rank' => $rank, 'verification_status' => $verification, 'source' => 'self', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function profile(Employee $e, bool $discoverable, int $spare): void
    {
        $this->actAs($e)->putJson(self::T.'my/profile', ['discoverable' => $discoverable, 'spare_hours_per_week' => $spare])->assertOk();
    }

    private function open(array $over = [], ?array $skills = null): array
    {
        $this->actAs($this->mgr);
        $o = $this->postJson(self::T.'opportunities', $over + ['title' => 'Data migration project', 'kind' => 'project', 'hours_per_week' => 10, 'starts_on' => '2026-10-20', 'openings' => 1,
            'skills' => $skills ?? [['skill_uuid' => $this->python, 'required_rank' => 3, 'mandatory' => true]]])->assertCreated()->json();
        $this->postJson(self::T."opportunities/{$o['uuid']}/status", ['status' => 'open'])->assertOk();

        return $o;
    }


    private function otherManager(): Employee
    {
        $this->context($this->admin->tenant_id); // a finished request clears the tenant context
        $e = $this->hire('Nadia Khan', ['role' => 'Manager']);

        return $e;
    }
    private function apply(Employee $e, string $opp)
    {
        return $this->actAs($e)->postJson(self::T."opportunities/$opp/apply", ['note' => 'Keen to help']);
    }

    public function test_drafts_need_a_skill_before_opening_and_are_invisible_to_employees(): void
    {
        $this->actAs($this->mgr);
        $o = $this->postJson(self::T.'opportunities', ['title' => 'Empty', 'kind' => 'gig', 'hours_per_week' => 5, 'starts_on' => '2026-10-20'])->assertCreated()->json();
        $this->postJson(self::T."opportunities/{$o['uuid']}/status", ['status' => 'open'])->assertStatus(422)->assertJsonValidationErrors('skills');
        $this->actAs($this->emp1)->getJson(self::T.'opportunities')->assertOk()->assertJsonPath('items', []);
    }

    public function test_matching_is_transparent_and_ranks_by_skill_fit(): void
    {
        $this->open();
        $this->know($this->emp1, $this->python, 3);
        $this->know($this->emp2, $this->python, 1);
        $this->know($this->emp3, $this->python, 4, 'rejected'); // a rejected claim counts as no skill

        $a = $this->actAs($this->emp1)->getJson(self::T.'opportunities')->assertOk()->json('items.0.match');
        $this->assertTrue($a['eligible']);
        $this->assertEquals(1.0, $a['score']);
        $this->assertSame('Python', $a['met'][0]['skill']);

        $b = $this->actAs($this->emp2)->getJson(self::T.'opportunities')->json('items.0.match');
        $this->assertFalse($b['eligible']);
        $this->assertEquals(0.33, $b['score']);
        $this->assertSame('Python', $b['gaps'][0]['skill']);

        $c = $this->actAs($this->emp3)->getJson(self::T.'opportunities')->json('items.0.match');
        $this->assertFalse($c['eligible']);
        $this->assertEquals(0.0, $c['score']);
    }

    public function test_optional_skills_lower_the_score_without_blocking_eligibility(): void
    {
        $this->open([], [['skill_uuid' => $this->python, 'required_rank' => 2, 'mandatory' => true], ['skill_uuid' => $this->sql, 'required_rank' => 2, 'mandatory' => false]]);
        $this->know($this->emp1, $this->python, 2);
        $m = $this->actAs($this->emp1)->getJson(self::T.'opportunities')->json('items.0.match');
        $this->assertTrue($m['eligible']);
        $this->assertEquals(0.67, $m['score']); // (2 + 0) / (2 + 1)
        $this->assertFalse($m['gaps'][0]['mandatory']);
    }

    public function test_people_are_discoverable_only_after_opting_in_and_only_inside_the_posters_scope(): void
    {
        $this->know($this->emp1, $this->python, 3);
        $this->know($this->emp2, $this->python, 4);
        $this->know($this->emp3, $this->python, 1); // opted in but not eligible
        $this->know($this->outsider, $this->python, 4); // opted in but not the poster's report
        $this->profile($this->emp1, true, 10);
        $this->profile($this->emp3, true, 10);
        $this->profile($this->outsider, true, 10);
        // emp2 never opted in.
        $o = $this->open();
        $found = $this->getJson(self::T."opportunities/{$o['uuid']}/candidates")->assertOk()->json('discoverable');
        $this->assertSame(['Arun Kumar'], array_column(array_column($found, 'employee'), 'name'));
        $this->assertSame(10, $found[0]['free_hours']);

        // Turning discovery off removes the person.
        $this->profile($this->emp1, false, 10);
        $this->actAs($this->mgr);
        $this->assertSame([], $this->getJson(self::T."opportunities/{$o['uuid']}/candidates")->json('discoverable'));
    }

    public function test_apply_rules(): void
    {
        $o = $this->open();
        $this->apply($this->emp1, $o['uuid'])->assertCreated()->assertJsonPath('status', 'interested');
        $this->apply($this->emp1, $o['uuid'])->assertStatus(422);
        $this->actAs($this->mgr);
        $draft = $this->postJson(self::T.'opportunities', ['title' => 'Draft', 'kind' => 'gig', 'hours_per_week' => 5, 'starts_on' => '2026-10-20'])->json();
        $this->apply($this->emp2, $draft['uuid'])->assertStatus(422);
        $this->apply($this->emp2, $o['uuid'])->assertCreated();
    }

    public function test_full_flow_shortlist_capacity_release_placement_and_evidence_without_changing_skill_level(): void
    {
        $this->know($this->emp1, $this->python, 3);
        $o = $this->open();
        $app = $this->apply($this->emp1, $o['uuid'])->json('uuid');
        $this->profile($this->emp1, true, 5);

        $this->actAs($this->mgr);
        $this->postJson(self::T."applications/$app/propose")->assertStatus(422); // must be shortlisted first
        $this->postJson(self::T."applications/$app/shortlist")->assertOk()->assertJsonPath('status', 'shortlisted');
        $this->postJson(self::T."applications/$app/propose")->assertStatus(422)->assertJsonValidationErrors('capacity'); // 5 spare < 10 needed

        $this->profile($this->emp1, true, 20);
        $this->actAs($this->mgr);
        $this->postJson(self::T."applications/$app/propose")->assertOk()->assertJsonPath('manager_notified', true)->assertJsonPath('application.status', 'awaiting_manager');
        $this->assertSame(1, DB::table('work_items')->where('source_module', 'talent')->where('source_entity', 'application:'.$app)->where('status', 'open')->count());
        $this->getJson(self::T.'approvals')->assertOk()->assertJsonCount(1, 'items');

        // The employee cannot release themselves.
        $this->actAs($this->emp1)->postJson(self::T."applications/$app/release", ['approve' => true])->assertForbidden();
        $this->actAs($this->mgr)->postJson(self::T."applications/$app/release", ['approve' => true])->assertOk()->assertJsonPath('status', 'placed');
        $this->assertSame(0, DB::table('work_items')->where('source_entity', 'application:'.$app)->where('status', 'open')->count());
        $this->assertSame('filled', DB::table('talent_opportunities')->where('uuid', $o['uuid'])->value('status'));
        $this->assertSame(10, $this->actAs($this->emp1)->getJson(self::T.'my/profile')->json('committed_hours'));
        $this->assertSame(10, $this->getJson(self::T.'my/profile')->json('free_hours'));

        // Completion: only requested skills can be attested; it creates evidence, never a level change.
        $this->actAs($this->mgr);
        $this->postJson(self::T."applications/$app/complete", ['skills' => [$this->sql]])->assertStatus(422)->assertJsonValidationErrors('skills');
        $this->postJson(self::T."applications/$app/complete", ['skills' => [$this->python], 'note' => 'Led the migration'])->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame(1, DB::table('learning_skill_evidence')->where('reference', 'talent:application:'.$app)->where('kind', 'project')->count());
        $this->assertSame(3, (int) DB::table('employee_skills')->where('person_id', $this->emp1->person_id)->value('level_rank'));
        $this->assertSame(0, $this->actAs($this->emp1)->getJson(self::T.'my/profile')->json('committed_hours'));
        $this->postJson(self::T."applications/$app/withdraw")->assertStatus(422); // finished work cannot be withdrawn
    }

    public function test_capacity_is_enforced_across_opportunities_and_a_denied_release_frees_it(): void
    {
        $this->profile($this->emp1, true, 20);
        $first = $this->open(['title' => 'First', 'hours_per_week' => 15]);
        $second = $this->open(['title' => 'Second', 'hours_per_week' => 10]);
        $a1 = $this->apply($this->emp1, $first['uuid'])->json('uuid');
        $a2 = $this->apply($this->emp1, $second['uuid'])->json('uuid');
        $this->actAs($this->mgr);
        foreach ([$a1, $a2] as $a) {
            $this->postJson(self::T."applications/$a/shortlist")->assertOk();
        }
        $this->postJson(self::T."applications/$a1/propose")->assertOk();
        $this->postJson(self::T."applications/$a2/propose")->assertStatus(422)->assertJsonValidationErrors('capacity'); // 15 reserved + 10 > 20
        $this->postJson(self::T."applications/$a1/release", ['approve' => false])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson(self::T."applications/$a1/release", ['approve' => false, 'reason' => 'Critical release week'])->assertOk()->assertJsonPath('status', 'release_denied');
        $this->postJson(self::T."applications/$a2/propose")->assertOk(); // capacity is free again
    }

    public function test_openings_are_not_over_reserved(): void
    {
        $this->profile($this->emp1, true, 40);
        $this->profile($this->emp2, true, 40);
        $o = $this->open();
        $a1 = $this->apply($this->emp1, $o['uuid'])->json('uuid');
        $a2 = $this->apply($this->emp2, $o['uuid'])->json('uuid');
        $this->actAs($this->mgr);
        $this->postJson(self::T."applications/$a1/shortlist");
        $this->postJson(self::T."applications/$a2/shortlist");
        $this->postJson(self::T."applications/$a1/propose")->assertOk();
        $this->postJson(self::T."applications/$a2/propose")->assertStatus(422)->assertJsonValidationErrors('openings');
    }

    public function test_only_the_employees_manager_or_a_talent_manager_can_release(): void
    {
        $this->profile($this->emp1, true, 20);
        $o = $this->open();
        $app = $this->apply($this->emp1, $o['uuid'])->json('uuid');
        $this->actAs($this->mgr);
        $this->postJson(self::T."applications/$app/shortlist");
        $this->postJson(self::T."applications/$app/propose")->assertOk();

        $otherManager = $this->otherManager();
        $this->actAs($otherManager)->postJson(self::T."applications/$app/release", ['approve' => true])->assertForbidden();
        $this->actAs($otherManager)->getJson(self::T.'approvals')->assertOk()->assertJsonCount(0, 'items');
        $this->signIn($this->admin)->postJson(self::T."applications/$app/release", ['approve' => true])->assertOk()->assertJsonPath('status', 'placed');
    }

    public function test_poster_decisions_need_reasons_and_cancelling_cascades(): void
    {
        $o = $this->open();
        $a1 = $this->apply($this->emp1, $o['uuid'])->json('uuid');
        $a2 = $this->apply($this->emp2, $o['uuid'])->json('uuid');
        $this->actAs($this->mgr);
        $this->postJson(self::T."applications/$a1/decline")->assertStatus(422);
        $this->postJson(self::T."applications/$a1/decline", ['reason' => 'Skills not a fit'])->assertOk()->assertJsonPath('status', 'declined');
        $this->postJson(self::T."opportunities/{$o['uuid']}/status", ['status' => 'cancelled'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson(self::T."opportunities/{$o['uuid']}/status", ['status' => 'cancelled', 'reason' => 'Budget frozen'])->assertOk();
        $this->assertSame('declined', DB::table('talent_applications')->where('uuid', $a2)->value('status'));
        $this->postJson(self::T."opportunities/{$o['uuid']}/status", ['status' => 'open'])->assertStatus(422);
    }

    public function test_employees_can_withdraw_and_cannot_touch_other_peoples_responses(): void
    {
        $o = $this->open();
        $app = $this->apply($this->emp1, $o['uuid'])->json('uuid');
        $this->actAs($this->emp2)->postJson(self::T."applications/$app/withdraw")->assertNotFound();
        $this->actAs($this->emp1)->postJson(self::T."applications/$app/withdraw")->assertOk()->assertJsonPath('status', 'withdrawn');
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        $o = $this->open();
        $this->actAs($this->emp1);
        $this->postJson(self::T.'opportunities', ['title' => 'X', 'kind' => 'gig', 'hours_per_week' => 5, 'starts_on' => '2026-10-20'])->assertForbidden();
        $this->getJson(self::T."opportunities/{$o['uuid']}/candidates")->assertForbidden();
        $this->getJson(self::T.'approvals')->assertForbidden();
        $this->actAs($this->emp2)->patchJson(self::T."opportunities/{$o['uuid']}", ['title' => 'Hijack'])->assertForbidden();

        $beta = app(\App\Domain\Access\WorkspaceProvisioner::class)->create('Beta', 'beta', 'admin@beta.test', 'StrongPassword123');
        $this->signIn($beta);
        $this->getJson(self::T."opportunities/{$o['uuid']}")->assertNotFound();
        $this->getJson(self::T.'posted')->assertOk()->assertJsonPath('items', []);
    }

    public function test_a_poster_cannot_manage_someone_elses_opportunity(): void
    {
        $o = $this->open();
        $other = $this->otherManager();
        $this->actAs($other)->postJson(self::T."opportunities/{$o['uuid']}/status", ['status' => 'closed'])->assertForbidden();
        $this->signIn($this->admin)->postJson(self::T."opportunities/{$o['uuid']}/status", ['status' => 'closed'])->assertOk(); // talent manager
    }
}
