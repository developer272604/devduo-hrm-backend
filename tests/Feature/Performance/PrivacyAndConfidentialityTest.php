<?php

namespace Tests\Feature\Performance;

use App\Domain\Performance\Models\{Pip, Review};
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

class PrivacyAndConfidentialityTest extends PerformanceTestCase
{
    public function test_peer_feedback_is_anonymous_thresholded_and_released_by_role(): void
    {
        $cycle = $this->cycle();
        $rev = $this->reviewOf($cycle, $this->emp1);
        $this->actAs($this->emp1)->postJson($this->p("reviews/$rev/peers"), ['employee_uuids' => [$this->emp2->uuid, $this->emp3->uuid]])->assertCreated();
        $this->actAs($this->emp1)->postJson($this->p("reviews/$rev/peers"), ['employee_uuids' => [$this->mgr->uuid]])->assertStatus(422); // manager cannot be a peer
        $this->actAs($this->emp2);
        $this->submit($this->pendingResponse($rev, 'peer'), 2, 2, 'Peer secret comment')->assertOk();
        $this->actAs($this->mgr);
        $this->submit($this->pendingResponse($rev, 'manager'), 4, 4, 'Manager view')->assertOk();

        // below the minimum (2) the peer content is withheld even from the manager
        $v = $this->getJson($this->p("reviews/$rev"))->assertOk()->json();
        $peer = collect($v['responses'])->firstWhere('type', 'peer');
        $this->assertArrayNotHasKey('comments', collect($v['responses'])->where('type', 'peer')->first());
        $this->assertTrue(collect($v['responses'])->where('type', 'peer')->first()['hidden']);

        $this->actAs($this->emp3);
        $this->submit($this->pendingResponse($rev, 'peer'), 3, 3, 'Another peer comment')->assertOk();
        $v = $this->actAs($this->mgr)->getJson($this->p("reviews/$rev"))->json();
        $peers = collect($v['responses'])->where('type', 'peer');
        $this->assertSame(['Peer secret comment', 'Another peer comment'], $peers->pluck('comments')->all());
        $this->assertSame([null, null], $peers->pluck('reviewer')->all()); // anonymous to the manager
        // only holders of the reveal permission see who wrote it
        $names = $this->signIn($this->admin)->getJson($this->p("reviews/$rev"))->json('responses');
        $this->assertContains('Bela Shah', collect($names)->pluck('reviewer')->all());
        $this->context($this->admin->tenant_id);
        $hr = $this->userWithRole('HR administrator', 'hr@alpha.test');
        $this->signIn($hr);
        $this->assertSame([null, null], collect($this->getJson($this->p("reviews/$rev"))->json('responses'))->where('type', 'peer')->pluck('reviewer')->all());

        // the employee sees only their own response before release; never the manager or peer content
        $s = $this->actAs($this->emp1)->getJson($this->p("reviews/$rev"))->json();
        $this->assertSame('subject', $s['role']);
        $this->assertArrayNotHasKey('computed_score', $s);
        $this->assertArrayNotHasKey('trace', $s);
        foreach ($s['responses'] as $row) { if (! $row['mine']) { $this->assertArrayNotHasKey('comments', $row); $this->assertArrayNotHasKey('items', $row); } }
        // a peer reviewer sees only their own response; an unrelated colleague gets 404
        $p = $this->actAs($this->emp2)->getJson($this->p("reviews/$rev"))->json();
        $this->assertSame('reviewer', $p['role']);
        $this->assertSame(1, collect($p['responses'])->where('mine', true)->count());
        $this->assertNull(collect($p['responses'])->firstWhere('type', 'manager')['comments'] ?? null);
        $this->actAs($this->outsider)->getJson($this->p("reviews/$rev"))->assertNotFound();
        $this->actAs($this->emp2)->postJson($this->p("reviews/$rev/acknowledge"))->assertForbidden();
        // out-of-scope manager (someone else's reviews) is not allowed either
        $other = $this->reviewOf($cycle, $this->outsider);
        $this->actAs($this->mgr)->getJson($this->p("reviews/$other"))->assertNotFound();
    }

    public function test_feedback_is_private_by_default_and_manager_visibility_is_opt_in(): void
    {
        $this->actAs($this->emp2);
        $private = $this->postJson($this->p('feedback'), ['employee_uuid' => $this->emp1->uuid, 'message' => 'Great demo today', 'kind' => 'recognition'])->assertCreated()->json('uuid');
        $shared = $this->postJson($this->p('feedback'), ['employee_uuid' => $this->emp1->uuid, 'message' => 'Share with manager', 'visibility' => 'recipient_manager'])->assertCreated()->json('uuid');
        $this->postJson($this->p('feedback'), ['employee_uuid' => $this->emp2->uuid, 'message' => 'To myself'])->assertStatus(422);
        $this->actAs($this->emp1);
        $this->assertCount(2, $this->getJson($this->p('feedback/received'))->json('data'));
        $this->actAs($this->mgr);
        $about = collect($this->getJson($this->p('employees/'.$this->emp1->uuid.'/feedback'))->assertOk()->json('data'));
        $this->assertSame([$shared], $about->pluck('uuid')->all());
        $this->assertNotContains($private, $about->pluck('uuid')->all());
        $this->actAs($this->emp3)->getJson($this->p('employees/'.$this->emp1->uuid.'/feedback'))->assertForbidden();
        $this->signIn($this->admin)->getJson($this->p('employees/'.$this->emp1->uuid.'/feedback'))->assertForbidden(); // HR has no default access to feedback
        $this->actAs($this->emp2);
        $this->assertCount(2, $this->getJson($this->p('feedback/given'))->json('data'));
    }

    public function test_one_on_one_private_notes_stay_with_the_author(): void
    {
        $this->actAs($this->mgr);
        $this->postJson($this->p('one-on-ones'), ['employee_uuid' => $this->emp1->uuid, 'held_on' => '2026-10-06', 'shared_notes' => 'Agreed on goals', 'private_notes' => 'Watch workload',
            'action_items' => [['text' => 'Draft plan', 'owner' => 'employee']]])->assertCreated()->assertJsonPath('private_notes', 'Watch workload');
        $this->assertSame('Watch workload', $this->getJson($this->p('employees/'.$this->emp1->uuid.'/one-on-ones'))->json('data.0.private_notes'));
        $row = $this->actAs($this->emp1)->getJson($this->p('employees/'.$this->emp1->uuid.'/one-on-ones'))->assertOk()->json('data.0');
        $this->assertSame('Agreed on goals', $row['shared_notes']);
        $this->assertNull($row['private_notes']);
        $this->actAs($this->emp2)->getJson($this->p('employees/'.$this->emp1->uuid.'/one-on-ones'))->assertForbidden();
        $this->signIn($this->admin)->getJson($this->p('employees/'.$this->emp1->uuid.'/one-on-ones'))->assertForbidden();
        $this->actAs($this->emp2)->postJson($this->p('one-on-ones'), ['employee_uuid' => $this->emp1->uuid, 'held_on' => '2026-10-06', 'shared_notes' => 'x'])->assertForbidden();
        // stored encrypted
        $this->assertStringNotContainsString('Watch workload', (string) DB::table('performance_one_on_ones')->value('private_notes'));
    }

    public function test_pip_is_confidential_audited_and_never_automatic(): void
    {
        $this->actAs($this->mgr)->getJson($this->p('pips'))->assertForbidden();
        $this->actAs($this->mgr)->postJson($this->p('pips'), [])->assertForbidden();
        $this->signIn($this->admin);
        $body = ['employee_uuid' => $this->emp1->uuid, 'reason' => 'Missed three consecutive delivery milestones', 'support_plan' => 'Weekly coaching', 'start_date' => '2026-10-07', 'end_date' => '2026-12-07',
            'checkpoints' => [['due_on' => '2026-11-07', 'expectation' => 'Deliver milestone A'], ['due_on' => '2026-12-01', 'expectation' => 'Deliver milestone B']]];
        $this->postJson($this->p('pips'), ['checkpoints' => [['due_on' => '2027-01-01', 'expectation' => 'x']]] + $body)->assertStatus(422); // outside period
        $pip = $this->postJson($this->p('pips'), $body)->assertCreated()->json('uuid');
        $this->postJson($this->p('pips'), $body)->assertStatus(422); // one open plan per person
        $this->assertStringNotContainsString('delivery milestones', (string) DB::table('performance_pips')->value('reason'));
        $this->actAs($this->emp1)->getJson($this->p('pips/mine'))->assertOk()->assertJsonCount(0, 'data'); // draft is not shared
        $this->actAs($this->emp1)->getJson($this->p("pips/$pip"))->assertNotFound();
        $this->signIn($this->admin)->postJson($this->p("pips/$pip/activate"))->assertOk()->assertJsonPath('status', 'active');
        $mine = $this->actAs($this->emp1)->getJson($this->p('pips/mine'))->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->actAs($this->emp1)->postJson($this->p("pips/$pip/complete"), ['outcome' => 'success', 'note' => 'self close'])->assertForbidden();
        $this->actAs($this->emp2)->getJson($this->p("pips/$pip"))->assertNotFound();
        $this->actAs($this->mgr)->getJson($this->p("pips/$pip"))->assertNotFound();
        $this->signIn($this->admin);
        $cps = $this->getJson($this->p("pips/$pip"))->assertOk()->json('checkpoints');
        $this->postJson($this->p("pips/$pip/complete"), ['outcome' => 'success', 'note' => 'Too early'])->assertStatus(422); // checkpoints pending
        $this->postJson($this->p("pips/$pip/checkpoints/{$cps[0]['uuid']}"), ['status' => 'met', 'note' => 'On time'])->assertOk();
        $this->postJson($this->p("pips/$pip/checkpoints/{$cps[1]['uuid']}"), ['status' => 'partially_met'])->assertOk();
        $this->postJson($this->p("pips/$pip/complete"), ['outcome' => 'success', 'note' => 'Human decision recorded'])->assertOk()->assertJsonPath('status', 'completed_success');
        $this->context($this->admin->tenant_id);
        $this->assertTrue(AuditLog::where('action', 'pip.viewed')->exists());
        $this->assertTrue(AuditLog::where('action', 'pip.activated')->exists());
        $this->assertSame($this->emp1->id, Pip::first()->employee_id);
    }

    public function test_authorization_gates_on_configuration_and_reports(): void
    {
        $this->actAs($this->emp1);
        $this->postJson($this->p('cycles'), [])->assertForbidden();
        $this->postJson($this->p('rating-scales'), [])->assertForbidden();
        $this->getJson($this->p('dashboard'))->assertForbidden();
        $this->getJson($this->p('outcomes'))->assertForbidden();
        $this->postJson($this->p('cycles/'.\Illuminate\Support\Str::uuid().'/calibration/start'))->assertForbidden();
        $this->actAs($this->mgr)->getJson($this->p('dashboard'))->assertOk();
        $this->getJson($this->p('templates'))->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson($this->p('goals'))->assertUnauthorized();
    }

    public function test_dashboard_scopes_and_suppresses_small_groups(): void
    {
        $cycle = $this->cycle();
        $rev = $this->reviewOf($cycle, $this->emp1);
        $this->actAs($this->emp1);
        $this->submit($this->pendingResponse($rev, 'self'), 4, 4)->assertOk();
        $d = $this->actAs($this->mgr)->getJson($this->p('dashboard'))->assertOk()->json();
        $this->assertSame(4, $d['reviews']['total']); // own review + direct reports only (outsider excluded)
        $this->assertTrue($d['distribution']['suppressed']);
        $this->assertSame(1, $d['completion']['self']['submitted']);
        $all = $this->signIn($this->admin)->getJson($this->p('dashboard'))->json();
        $this->assertGreaterThan(3, $all['reviews']['total']);
    }
}
