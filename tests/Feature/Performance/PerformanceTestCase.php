<?php

namespace Tests\Feature\Performance;

use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PeopleFixtures;
use Tests\TestCase;

abstract class PerformanceTestCase extends TestCase
{
    use RefreshDatabase, PeopleFixtures;

    protected const API = '/api/v1/performance/';

    protected User $checker;

    protected Employee $mgr;

    protected Employee $emp1;

    protected Employee $emp2;

    protected Employee $emp3;

    protected Employee $outsider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootTenant();
        $this->checker = $this->userWithRole('Workspace administrator', 'checker@alpha.test', 'Checker');
        $this->mgr = $this->hire('Meera Rao', ['role' => 'Manager']);
        $this->emp1 = $this->hire('Arun Kumar', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->emp2 = $this->hire('Bela Shah', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->emp3 = $this->hire('Chen Wei', ['role' => 'Team member', 'manager' => $this->mgr]);
        $this->outsider = $this->hire('Omar Diaz', ['role' => 'Team member']);
        $this->signIn($this->admin);
    }

    protected function userOf(Employee $e): User
    {
        return User::findOrFail(\DB::table('people')->where('id', $e->person_id)->value('user_id'));
    }

    protected function actAs(Employee|User $who): static
    {
        return $this->signIn($who instanceof Employee ? $this->userOf($who) : $who);
    }

    protected function p(string $path): string
    {
        return self::API.$path;
    }

    /** Scale 1-5, template (goals 50 + competencies 50; manager 60 / self 20 / peer 20), competency TEAMWORK. Returns template uuid. */
    protected function template(array $weights = ['manager' => 60, 'self' => 20, 'peer' => 20, 'skip' => 0]): string
    {
        $this->signIn($this->admin);
        $this->postJson($this->p('rating-scales'), ['code' => 'FIVE', 'name' => 'Five point', 'min' => 1, 'max' => 5, 'labels' => [['value' => 1, 'label' => 'Needs improvement'], ['value' => 2, 'label' => 'Partially meets'], ['value' => 3, 'label' => 'Meets'], ['value' => 4, 'label' => 'Exceeds'], ['value' => 5, 'label' => 'Outstanding']]])->assertCreated();
        $this->postJson($this->p('competencies'), ['code' => 'TEAMWORK', 'name' => 'Teamwork'])->assertCreated();

        return $this->postJson($this->p('templates'), ['name' => 'Annual', 'scale_code' => 'FIVE', 'reviewer_weights' => $weights,
            'sections' => [['key' => 'goals', 'title' => 'Goals', 'type' => 'goals', 'weight' => 50, 'mandatory' => true], ['key' => 'comp', 'title' => 'Competencies', 'type' => 'competencies', 'weight' => 50]]])->assertCreated()->json('uuid');
    }

    protected function cycle(array $extra = [], ?array $weights = null): string
    {
        $tpl = $weights ? $this->template($weights) : $this->template();
        $uuid = $this->postJson($this->p('cycles'), $extra + ['name' => 'FY26', 'template_uuid' => $tpl, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'self_due' => '2026-10-15', 'manager_due' => '2026-10-20',
            'settings' => ['min_peer_responses' => 2]])->assertCreated()->json('uuid');
        $this->postJson($this->p("cycles/$uuid/publish"))->assertOk();
        $this->postJson($this->p("cycles/$uuid/launch"))->assertOk();

        return $uuid;
    }

    protected function reviewOf(string $cycleUuid, Employee $e): string
    {
        $this->actAs($e);

        return collect($this->getJson($this->p("reviews?cycle_uuid=$cycleUuid"))->assertOk()->json('data'))->first(fn ($r) => $r['employee']['uuid'] === $e->uuid)['uuid'];
    }

    /** Pending response uuid of a given type for the signed-in user on a review. */
    protected function pendingResponse(string $reviewUuid, string $type): string
    {
        $list = $this->getJson($this->p('reviews'))->assertOk()->json('data');

        return collect($list)->firstWhere('uuid', $reviewUuid)['pending_responses'][array_search($type, array_column(collect($list)->firstWhere('uuid', $reviewUuid)['pending_responses'], 'type'))]['uuid'];
    }

    protected function submit(string $respUuid, int|float $goals, int|float $comp, ?string $comment = null)
    {
        return $this->postJson($this->p("review-responses/$respUuid/submit"), ['comments' => $comment, 'items' => [
            ['section' => 'goals', 'ref' => 'g1', 'label' => 'Delivery', 'rating' => $goals], ['section' => 'comp', 'ref' => 'TEAMWORK', 'rating' => $comp]]]);
    }
}
