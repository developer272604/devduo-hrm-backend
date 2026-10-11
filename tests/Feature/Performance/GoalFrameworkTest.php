<?php

namespace Tests\Feature\Performance;

use App\Domain\Performance\Models\Goal;

class GoalFrameworkTest extends PerformanceTestCase
{
    private function companyGoal(): string
    {
        $this->signIn($this->admin);

        return $this->postJson($this->p('goals'), ['level' => 'company', 'kind' => 'objective', 'title' => 'Grow revenue', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertCreated()->assertJsonPath('status', 'active')->json('uuid');
    }

    public function test_cascade_and_progress_rollup_with_decimal_weights(): void
    {
        $company = $this->companyGoal();
        $this->postJson($this->p("goals/$company/cascade"), ['owner_uuids' => [$this->emp1->uuid, $this->emp2->uuid], 'weight' => 40])->assertCreated()->assertJsonPath('created', 2);
        // idempotent per (parent, owner)
        $this->postJson($this->p("goals/$company/cascade"), ['owner_uuids' => [$this->emp1->uuid]])->assertCreated()->assertJsonPath('created', 0);

        $children = collect($this->getJson($this->p('goals?level=individual'))->assertOk()->json('data'));
        $this->assertCount(2, $children);
        $a = $children->firstWhere('owner.uuid', $this->emp1->uuid)['uuid'];
        $b = $children->firstWhere('owner.uuid', $this->emp2->uuid)['uuid'];

        $this->actAs($this->emp1)->postJson($this->p("goals/$a/checkins"), ['progress' => 80, 'note' => 'Good month'])->assertCreated()->assertJsonPath('progress_after', '80.00');
        $this->actAs($this->emp2)->postJson($this->p("goals/$b/checkins"), ['progress' => 33.33, 'confidence' => 'at_risk'])->assertCreated();
        // both weight 40 -> (40*80 + 40*33.33) / 80 = 56.665 -> 56.67 (half up)
        $this->signIn($this->admin)->getJson($this->p("goals/$company"))->assertOk()->assertJsonPath('progress', '56.67');
        $this->getJson($this->p("goals/$a"))->assertJsonPath('checkins.0.progress_before', '0.00')->assertJsonPath('checkins.0.progress_after', '80.00');
        $tree = $this->getJson($this->p("goals/$company/alignment"))->assertOk()->json();
        $this->assertCount(2, $tree['goal']['children']);
        $this->getJson($this->p("goals/$a/alignment"))->assertJsonPath('ancestors.0.uuid', $company);
    }

    public function test_key_results_and_kpi_progress(): void
    {
        $this->actAs($this->mgr);
        $okr = $this->postJson($this->p('goals'), ['level' => 'individual', 'owner_uuid' => $this->emp1->uuid, 'kind' => 'objective', 'title' => 'Ship v2', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertCreated()->assertJsonPath('status', 'active')->json('uuid');
        $k1 = $this->postJson($this->p("goals/$okr/key-results"), ['title' => 'Cut latency', 'weight' => 2, 'start_value' => 500, 'target_value' => 200, 'direction' => 'decrease'])->assertCreated()->json('uuid');
        $this->postJson($this->p("goals/$okr/key-results"), ['title' => 'Launch', 'weight' => 1, 'start_value' => 0, 'target_value' => 3])->assertCreated();
        $this->postJson($this->p("goals/$okr/checkins"), ['key_result_uuid' => $k1, 'value' => 350, 'note' => 'half way'])->assertCreated();
        // KR1 50.00 (w2), KR2 0 (w1) -> 100/3 = 33.33
        $this->getJson($this->p("goals/$okr"))->assertJsonPath('progress', '33.33')->assertJsonCount(2, 'key_results');

        $kpi = $this->postJson($this->p('goals'), ['level' => 'individual', 'owner_uuid' => $this->emp1->uuid, 'kind' => 'kpi', 'title' => 'Tickets closed', 'unit' => 'count', 'start_value' => 0, 'target_value' => 3, 'current_value' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertCreated()->json('uuid');
        $this->getJson($this->p("goals/$kpi"))->assertJsonPath('progress', '33.33')->assertJsonPath('progress_mode', 'metric');
        $this->postJson($this->p("goals/$kpi/checkins"), ['value' => 9])->assertCreated()->assertJsonPath('progress_after', '100.00'); // clamped
    }

    public function test_goal_approval_is_maker_checker_and_scoped(): void
    {
        $this->actAs($this->emp1);
        $g = $this->postJson($this->p('goals'), ['level' => 'individual', 'title' => 'Learn Rust', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'weight' => 100])->assertCreated()->assertJsonPath('status', 'draft')->json('uuid');
        $this->postJson($this->p("goals/$g/checkins"), ['progress' => 10])->assertStatus(422); // not active yet
        $this->postJson($this->p("goals/$g/submit"))->assertOk()->assertJsonPath('status', 'pending_approval');
        $this->postJson($this->p("goals/$g/decision"), ['decision' => 'approve'])->assertForbidden(); // team member holds no approve permission
        $this->actAs($this->outsider)->getJson($this->p("goals/$g"))->assertOk(); // team visibility: same department
        $this->actAs($this->mgr)->postJson($this->p("goals/$g/decision"), ['decision' => 'reject'])->assertStatus(422); // reason required
        $this->postJson($this->p("goals/$g/decision"), ['decision' => 'approve'])->assertOk()->assertJsonPath('status', 'active');
        $this->actAs($this->emp1)->postJson($this->p("goals/$g/checkins"), ['progress' => 10])->assertCreated();
        // changing a measurable field re-opens approval and bumps the version
        $this->patchJson($this->p("goals/$g"), ['weight' => 50])->assertOk()->assertJsonPath('status', 'pending_approval')->assertJsonPath('version', 2);
        // manager of someone else cannot approve outside their reports
        $other = $this->actAs($this->outsider)->postJson($this->p('goals'), ['level' => 'individual', 'title' => 'Other', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->json('uuid');
        $this->postJson($this->p("goals/$other/submit"))->assertOk();
        $this->actAs($this->mgr)->postJson($this->p("goals/$other/decision"), ['decision' => 'approve'])->assertForbidden();
    }

    public function test_visibility_and_authorization(): void
    {
        $this->actAs($this->emp1);
        $private = $this->postJson($this->p('goals'), ['level' => 'individual', 'title' => 'Private aim', 'visibility' => 'owner', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertCreated()->json('uuid');
        $this->actAs($this->emp2)->getJson($this->p("goals/$private"))->assertNotFound();
        $this->actAs($this->outsider)->getJson($this->p("goals/$private"))->assertNotFound();
        $this->actAs($this->mgr)->getJson($this->p("goals/$private"))->assertOk(); // manager reaches own reports
        $this->signIn($this->admin)->getJson($this->p("goals/$private"))->assertOk();
        // employees cannot create company goals nor set goals for peers
        $this->actAs($this->emp1)->postJson($this->p('goals'), ['level' => 'company', 'title' => 'X', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertForbidden();
        $this->postJson($this->p('goals'), ['level' => 'individual', 'owner_uuid' => $this->emp2->uuid, 'title' => 'X', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertForbidden();
        // a manager cannot set goals for someone outside their reports
        $this->actAs($this->mgr)->postJson($this->p('goals'), ['level' => 'individual', 'owner_uuid' => $this->outsider->uuid, 'title' => 'X', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertForbidden();
        // alignment must point up the hierarchy
        $this->actAs($this->emp1)->postJson($this->p('goals'), ['level' => 'individual', 'title' => 'Y', 'parent_uuid' => $private, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertStatus(422);
    }

    public function test_tenant_isolation(): void
    {
        $g = $this->companyGoal();
        $alpha = $this->admin->tenant_id;
        $other = $this->bootTenant('beta');
        $this->signIn($other)->getJson($this->p("goals/$g"))->assertNotFound();
        $this->getJson($this->p('goals'))->assertOk()->assertJsonCount(0, 'data');
        $this->context($alpha);
        $this->assertSame(1, Goal::count());
    }
}
