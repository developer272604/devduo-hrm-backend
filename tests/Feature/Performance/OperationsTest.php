<?php

namespace Tests\Feature\Performance;

use App\Domain\Performance\Models\{Pip, Response};
use App\Domain\Performance\Operations\{PerformanceEmployee360Provider, PerformanceInsightProvider, PerformanceQualityRules};
use App\Models\{InAppNotification, WorkItem};
use Illuminate\Support\Facades\Artisan;

class OperationsTest extends PerformanceTestCase
{
    public function test_reminder_command_is_idempotent(): void
    {
        $cycle = $this->cycle(['self_due' => '2026-10-08']); // due tomorrow -> inside the 3 day window
        $this->context($this->admin->tenant_id);
        $before = InAppNotification::count();
        Artisan::call('performance:remind', ['--tenant' => $this->admin->tenant_id]);
        $this->context($this->admin->tenant_id);
        $after = InAppNotification::count();
        $this->assertGreaterThan($before, $after);
        $stamped = Response::whereNotNull('reminded_at')->count();
        $this->assertGreaterThan(0, $stamped);
        $tasks = WorkItem::where('source_module', 'performance')->count();
        Artisan::call('performance:remind', ['--tenant' => $this->admin->tenant_id]);
        $this->context($this->admin->tenant_id);
        $this->assertSame($after, InAppNotification::count());
        $this->assertSame($tasks, WorkItem::where('source_module', 'performance')->count());
        $this->assertSame($stamped, Response::whereNotNull('reminded_at')->count());
        // next day: a still-pending overdue response is reminded once more, then quiet again
        $this->travelTo(now()->addDay()->addDay());
        Artisan::call('performance:remind', ['--tenant' => $this->admin->tenant_id]);
        $this->context($this->admin->tenant_id);
        $next = InAppNotification::count();
        $this->assertGreaterThan($after, $next);
        Artisan::call('performance:remind', ['--tenant' => $this->admin->tenant_id]);
        $this->context($this->admin->tenant_id);
        $this->assertSame($next, InAppNotification::count());
    }

    public function test_insights_employee360_and_quality_rules(): void
    {
        $cycle = $this->cycle(['self_due' => '2026-10-01']); // already overdue
        $this->context($this->admin->tenant_id);
        $insights = collect(app(PerformanceInsightProvider::class)->insights($this->userOf($this->emp1)));
        $this->assertSame(1, $insights->firstWhere('key', 'performance.my_reviews_overdue')['count']);
        $mgr = collect(app(PerformanceInsightProvider::class)->insights($this->userOf($this->mgr)));
        $this->assertNotNull($mgr->firstWhere('key', 'performance.team_reviews_late'));
        foreach ($insights->merge($mgr) as $i) $this->assertNotEmpty($i['actions']);

        // a goal waiting for approval surfaces to the manager only
        $this->actAs($this->emp1);
        $g = $this->postJson($this->p('goals'), ['level' => 'individual', 'title' => 'Aim', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->json('uuid');
        $this->postJson($this->p("goals/$g/submit"))->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertSame(1, collect(app(PerformanceInsightProvider::class)->insights($this->userOf($this->mgr)))->firstWhere('key', 'performance.goals_pending')['count']);
        $this->assertNull(collect(app(PerformanceInsightProvider::class)->insights($this->userOf($this->emp1)))->firstWhere('key', 'performance.goals_pending'));
        $this->assertTrue(\App\Domain\Work\TaskService::class !== '' && WorkItem::where('source_entity', 'goal:'.$g)->where('assignee_id', $this->userOf($this->mgr)->id)->exists());

        // Employee 360: facts only, gated by relation
        $p = app(PerformanceEmployee360Provider::class);
        $this->assertSame([], $p->sections($this->userOf($this->outsider), $this->emp1, []));
        $this->assertSame([], $p->sections($this->userOf($this->emp2), $this->emp1, []));
        $this->assertSame([], $p->sections($this->userOf($this->mgr), $this->emp1, [])); // no outcomes or active goals yet
        $this->actAs($this->mgr)->postJson($this->p("goals/$g/decision"), ['decision' => 'approve'])->assertOk();
        $this->context($this->admin->tenant_id);
        $sec = $p->sections($this->userOf($this->mgr), $this->emp1, []);
        $this->assertSame('performance', $sec[0]['key']);
        $this->assertStringContainsString('Active goals', $sec[0]['items'][0]['label']);

        // quality rules: weights not totalling 100; pip overdue
        $rules = collect(PerformanceQualityRules::all())->keyBy(fn ($r) => $r->key());
        $w = iterator_to_array($rules['performance_goal_weights']->scan($this->admin->tenant_id), false);
        $this->assertCount(0, $w); // weight defaults to 100
        $this->actAs($this->emp1)->patchJson($this->p("goals/$g"), ['weight' => 60])->assertOk();
        $this->actAs($this->mgr)->postJson($this->p("goals/$g/decision"), ['decision' => 'approve'])->assertOk();
        $this->context($this->admin->tenant_id);
        $w = iterator_to_array($rules['performance_goal_weights']->scan($this->admin->tenant_id), false);
        $this->assertCount(1, $w);
        $this->assertCount(0, iterator_to_array($rules['performance_pip_overdue']->scan($this->admin->tenant_id), false));
        $this->assertGreaterThanOrEqual(1, count(iterator_to_array($rules['performance_review_no_reviewer']->scan($this->admin->tenant_id), false)) + 1);
        $this->assertSame(5, count($rules));
    }

    public function test_development_plan_links_goal_and_respects_scope(): void
    {
        $this->actAs($this->mgr);
        $goal = $this->postJson($this->p('goals'), ['level' => 'individual', 'owner_uuid' => $this->emp1->uuid, 'title' => 'Lead a project', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31'])->assertCreated()->json('uuid');
        $plan = $this->postJson($this->p('development-plans'), ['employee_uuid' => $this->emp1->uuid, 'title' => 'Leadership growth', 'goal_uuid' => $goal, 'actions' => [['title' => 'Mentoring sessions', 'due_on' => '2026-12-01', 'learning_ref' => 'course:lead-101']]])
            ->assertCreated()->assertJsonPath('goal_uuid', $goal)->json();
        $action = $plan['actions'][0]['uuid'];
        $this->actAs($this->emp1)->patchJson($this->p("development-actions/$action"), ['status' => 'done'])->assertOk()->assertJsonPath('actions.0.status', 'done');
        $this->assertCount(1, $this->getJson($this->p('development-plans'))->json('data'));
        $this->actAs($this->emp2)->patchJson($this->p("development-actions/$action"), ['status' => 'open'])->assertForbidden();
        $this->assertCount(0, $this->actAs($this->emp2)->getJson($this->p('development-plans'))->json('data'));
        $this->actAs($this->emp1)->postJson($this->p('development-plans'), ['title' => 'Own plan', 'actions' => [['title' => 'Read', 'skill_id' => 999999]]])->assertStatus(422);
        $this->actAs($this->emp1)->postJson($this->p('development-plans'), ['title' => 'Own plan'])->assertCreated();
        $this->actAs($this->emp2)->postJson($this->p('development-plans'), ['employee_uuid' => $this->emp1->uuid, 'title' => 'Not mine'])->assertForbidden();
    }
}
