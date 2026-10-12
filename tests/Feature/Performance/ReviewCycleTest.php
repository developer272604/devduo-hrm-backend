<?php

namespace Tests\Feature\Performance;

use App\Domain\Performance\Contracts\PerformanceOutcomeProvider;
use App\Domain\Performance\Models\{Item, Response, Review, ReviewAppeal};
use App\Models\AuditLog;

class ReviewCycleTest extends PerformanceTestCase
{
    public function test_cycle_to_reviews_to_calibration_to_finalize_with_scoring_trace(): void
    {
        $cycle = $this->cycle();
        // launch is idempotent: re-launching an open cycle creates nothing new
        $second = $this->postJson($this->p("cycles/$cycle/launch"))->assertOk()->json();
        $this->assertSame(0, $second['created']);
        $this->assertGreaterThanOrEqual(4, $second['existing']);

        $rev = $this->reviewOf($cycle, $this->emp1);
        // self review (goals 5, comp 5 -> 5.000)
        $this->actAs($this->emp1);
        $this->submit($this->pendingResponse($rev, 'self'), 5, 5)->assertOk()->assertJsonPath('score', '5.000');
        // peers nominated by the subject, one responds
        $this->postJson($this->p("reviews/$rev/peers"), ['employee_uuids' => [$this->emp2->uuid, $this->emp3->uuid]])->assertCreated()->assertJsonPath('added', 2);
        $this->actAs($this->emp2);
        $this->submit($this->pendingResponse($rev, 'peer'), 3, 3)->assertOk();
        // manager review: goals 4, comp 5 -> section avg 4 and 5, equal weights -> 4.500
        $this->actAs($this->mgr);
        $this->submit($this->pendingResponse($rev, 'manager'), 4, 5, 'Strong year')->assertOk()->assertJsonPath('score', '4.500');

        // one peer < min 2: excluded, weights renormalized: (60*4.5 + 20*5) / 80 = 4.625
        $view = $this->getJson($this->p("reviews/$rev"))->assertOk()->json();
        $this->assertSame('4.625', $view['computed_score']);
        $this->assertSame(['goals', 'comp'], array_column($view['template']['sections'], 'key'));
        $this->assertContains('TEAMWORK', array_column($view['template']['competencies'], 'code'));
        $peerLine = collect($view['trace'])->firstWhere('type', 'peer');
        $this->assertFalse($peerLine['included']);
        $this->assertSame('80.00', collect($view['trace'])->firstWhere('step', 'combined')['weights_total']);

        $this->actAs($this->emp3);
        $this->submit($this->pendingResponse($rev, 'peer'), 3, 3)->assertOk();
        // 2 peers (avg 3.000): (60*4.5 + 20*5 + 20*3) / 100 = 4.300
        $this->actAs($this->mgr);
        $view = $this->getJson($this->p("reviews/$rev"))->json();
        $this->assertSame('4.300', $view['computed_score']);
        $this->assertTrue(collect($view['trace'])->firstWhere('type', 'peer')['included']);

        // calibration
        $this->signIn($this->admin);
        $this->postJson($this->p("cycles/$cycle/calibration/start"))->assertOk()->assertJsonPath('status', 'calibration');
        $this->actAs($this->emp1);
        $this->submit('00000000-0000-0000-0000-000000000000', 1, 1)->assertNotFound();
        $this->signIn($this->admin);
        $this->postJson($this->p("reviews/$rev/calibration-changes"), ['to_score' => 9, 'justification' => 'Out of range test'])->assertStatus(422);
        $this->postJson($this->p("reviews/$rev/calibration-changes"), ['to_score' => 4, 'justification' => 'x'])->assertStatus(422);
        $change = $this->postJson($this->p("reviews/$rev/calibration-changes"), ['to_score' => 4, 'justification' => 'Consistent with team peers'])->assertCreated()->json('uuid');
        $this->postJson($this->p("calibration-changes/$change/decision"), ['decision' => 'approve'])->assertForbidden(); // proposer cannot decide
        $this->actAs($this->mgr)->postJson($this->p("calibration-changes/$change/decision"), ['decision' => 'approve'])->assertForbidden();
        $this->signIn($this->admin)->postJson($this->p("cycles/$cycle/finalize"))->assertStatus(422); // pending change
        $this->signIn($this->checker)->postJson($this->p("calibration-changes/$change/decision"), ['decision' => 'approve'])->assertOk()->assertJsonPath('status', 'approved');
        $ws = $this->getJson($this->p("cycles/$cycle/calibration"))->assertOk()->json();
        $this->assertSame('Exceeds', collect($ws['reviews'])->firstWhere('uuid', $rev)['final_rating']);

        $result = $this->postJson($this->p("cycles/$cycle/finalize"))->assertOk()->json();
        $this->assertGreaterThanOrEqual(1, $result['finalized']);
        $this->assertNotEmpty($result['not_ready']); // others have no manager review
        $this->assertSame('calibration', $result['cycle_status']);

        // immutability
        $this->context($this->admin->tenant_id);
        $review = Review::where('uuid', $rev)->first();
        $this->assertSame('finalized', $review->status);
        $this->assertSame('4.000', (string) number_format((float) $review->final_score, 3, '.', ''));
        $this->assertNotEmpty($review->snapshot['calibration']);
        try { $review->update(['final_score' => '5.000']); $this->fail('Finalized review changed.'); } catch (\LogicException $e) { $this->assertStringContainsString('finalized', $e->getMessage()); }
        try { Response::where('review_id', $review->id)->first()->update(['comments' => 'tampered']); $this->fail('Response changed.'); } catch (\LogicException) { $this->addToAssertionCount(1); }
        $this->actAs($this->mgr)->postJson($this->p("reviews/$rev/calibration-changes"), ['to_score' => 2, 'justification' => 'Late change attempt'])->assertForbidden();
        $this->signIn($this->admin)->postJson($this->p("reviews/$rev/calibration-changes"), ['to_score' => 2, 'justification' => 'Late change attempt'])->assertStatus(422);

        // audit trail for the rating change
        $this->context($this->admin->tenant_id);
        $this->assertTrue(AuditLog::where('action', 'review.rating_changed')->exists());
        $this->assertTrue(AuditLog::where('action', 'review.finalized')->exists());

        // employee acknowledges once; result visible now
        $this->actAs($this->emp1);
        $mine = $this->getJson($this->p("reviews/$rev"))->assertOk()->json();
        $this->assertSame('Exceeds', $mine['result']['final_rating']);
        $this->assertArrayNotHasKey('trace', $mine);
        $appeal = $this->postJson($this->p("reviews/$rev/appeals"), ['reason' => 'I want HR to review the calibration note and final rating context.'])->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('requested_by_me', true)->json('uuid');
        $this->postJson($this->p("reviews/$rev/appeals"), ['reason' => 'Second appeal should not be accepted while the first is pending.'])->assertStatus(422);
        $this->postJson($this->p("review-appeals/$appeal/decision"), ['decision' => 'approve', 'note' => 'I cannot decide my own appeal.'])->assertForbidden();

        $this->signIn($this->admin)->getJson($this->p('review-appeals?status=pending'))->assertOk()->assertJsonPath('data.0.uuid', $appeal);
        $this->postJson($this->p("review-appeals/$appeal/decision"), ['decision' => 'approve', 'note' => 'Appeal accepted for HR follow-up record.'])->assertOk()
            ->assertJsonPath('status', 'approved')->assertJsonPath('can_decide', false);
        $this->context($this->admin->tenant_id);
        $storedAppeal = ReviewAppeal::where('uuid', $appeal)->firstOrFail();
        $this->assertSame('approved', $storedAppeal->status);
        $this->assertStringNotContainsString('calibration note', \DB::table('performance_review_appeals')->where('uuid', $appeal)->value('reason'));
        $this->actAs($this->emp1);
        $this->postJson($this->p("reviews/$rev/acknowledge"), ['comment' => 'Thanks'])->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->postJson($this->p("reviews/$rev/acknowledge"))->assertStatus(422);

        // outcome contract (read only; no side effects)
        $this->context($this->admin->tenant_id);
        $latest = app(PerformanceOutcomeProvider::class)->latest($this->emp1->id);
        $this->assertSame('4.000', $latest['final_score']);
        $this->assertSame('Exceeds', $latest['final_rating']);
        $this->signIn($this->admin)->getJson($this->p('outcomes'))->assertOk()->assertJsonPath('data.0.final_score', '4.000');
        $this->actAs($this->mgr)->getJson($this->p('outcomes'))->assertForbidden();
    }

    private function pendingOrFail(string $rev, string $type): string
    {
        return Response::where('review_id', Review::where('uuid', $rev)->value('id'))->where('reviewer_type', $type)->value('uuid');
    }

    public function test_rating_must_stay_within_scale_and_mandatory_sections_are_enforced(): void
    {
        $cycle = $this->cycle();
        $rev = $this->reviewOf($cycle, $this->emp1);
        $this->actAs($this->emp1);
        $resp = $this->pendingResponse($rev, 'self');
        $this->submit($resp, 6, 3)->assertStatus(422)->assertJsonValidationErrors(['items.0.rating']);
        $this->postJson($this->p("review-responses/$resp/submit"), ['items' => [['section' => 'comp', 'ref' => 'TEAMWORK', 'rating' => 3]]])->assertStatus(422)->assertJsonValidationErrors(['items']);
        $this->postJson($this->p("review-responses/$resp/submit"), ['items' => [['section' => 'goals', 'ref' => 'g', 'rating' => 3], ['section' => 'comp', 'ref' => 'UNKNOWN', 'rating' => 3]]])->assertStatus(422);
        $this->submit($resp, 3.5, 4)->assertOk()->assertJsonPath('score', '3.750');
        $this->submit($resp, 3, 3)->assertStatus(422); // already submitted
        $this->context($this->admin->tenant_id);
        $this->assertSame(2, Item::count());
    }

    public function test_eligibility_filters_are_effective_dated_and_cycle_can_exclude_departments(): void
    {
        $this->signIn($this->admin);
        $tpl = $this->template();
        $this->context($this->admin->tenant_id);
        $hr = $this->hire('Hari Om', ['department' => 2]);
        $cycle = $this->postJson($this->p('cycles'), ['name' => 'Eng only', 'template_uuid' => $tpl, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
            'eligibility' => ['department_ids' => [$this->org['department'][0]], 'min_tenure_days' => 30]])->assertCreated()->json('uuid');
        $this->postJson($this->p("cycles/$cycle/launch"))->assertStatus(422); // not published
        $this->postJson($this->p("cycles/$cycle/publish"))->assertOk();
        $this->getJson($this->p("cycles/$cycle/eligibility"))->assertOk()->assertJsonPath('as_of', '2026-10-07');
        $this->postJson($this->p("cycles/$cycle/launch"))->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertFalse(Review::where('employee_id', $hr->id)->exists());
        $this->assertTrue(Review::where('employee_id', $this->emp1->id)->exists());
        // newly hired person (tenure < 30 days) is not eligible
        $new = $this->hire('Nina New', ['joined_on' => '2026-10-01']);
        $this->signIn($this->admin)->postJson($this->p("cycles/$cycle/launch"))->assertOk();
        $this->context($this->admin->tenant_id);
        $this->assertFalse(Review::where('employee_id', $new->id)->exists());
    }

    public function test_reviewer_change_before_submission(): void
    {
        $cycle = $this->cycle();
        $rev = $this->reviewOf($cycle, $this->emp1);
        $this->signIn($this->admin)->postJson($this->p("reviews/$rev/manager"), ['manager_uuid' => $this->emp1->uuid])->assertStatus(422);
        $this->postJson($this->p("reviews/$rev/manager"), ['manager_uuid' => $this->emp2->uuid])->assertOk();
        $this->actAs($this->mgr)->postJson($this->p('review-responses/'.$this->pendingOrFail($rev, 'manager').'/submit'), ['items' => [['section' => 'goals', 'ref' => 'g', 'rating' => 3]]])->assertNotFound();
        $this->actAs($this->emp2);
        $this->submit($this->pendingResponse($rev, 'manager'), 3, 3)->assertOk();
    }
}
