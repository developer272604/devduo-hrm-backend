<?php

namespace Tests\Feature\Learning;

class AssessmentTest extends LearningTestCase
{
    private function quiz(string $course, array $extra = []): string
    {
        $this->signIn($this->admin);

        return $this->postJson($this->p("courses/$course/assessments"), $extra + ['title' => 'Safety quiz', 'pass_percent' => 60, 'max_attempts' => 2, 'questions' => [
            ['text' => 'Pick the exit', 'options' => ['Window', 'Door', 'Wall'], 'correct' => [1], 'points' => 2],
            ['text' => 'Pick all extinguishers', 'options' => ['CO2', 'Water', 'Cake'], 'correct' => [0, 1], 'points' => 3],
        ]])->assertCreated()->json('uuid');
    }

    public function test_taker_view_hides_answers_and_scoring_is_server_side_with_attempt_limit(): void
    {
        $c = $this->course('QUIZ-1');
        $a = $this->quiz($c);
        $this->assign($c, [$this->emp1])->assertCreated();

        $this->actAs($this->emp1);
        $view = $this->getJson($this->p("courses/$c/assessment"))->assertOk();
        $this->assertStringNotContainsString('correct', $view->getContent());
        $this->assertSame(2, $view->json('attempts_remaining'));
        $this->getJson($this->p("courses/$c/assessments"))->assertForbidden(); // answer key is for authors only

        // 2 of 5 points = 40% -> fail; a partial multi-select answer is wrong.
        $r = $this->postJson($this->p("assessments/$a/attempts"), ['answers' => ['q1' => [1], 'q2' => [0]]])->assertCreated();
        $this->assertSame('40.00', $r->json('percent'));
        $this->assertFalse($r->json('passed'));
        $this->assertSame(1, $r->json('attempts_remaining'));
        $this->assertSame(['q1' => true, 'q2' => false], $r->json('results'));
        $this->assertStringNotContainsString('"correct"', $r->getContent());

        $this->postJson($this->p("assessments/$a/attempts"), ['answers' => ['q1' => [0], 'q2' => [0, 1]]])->assertCreated()->assertJsonPath('percent', '60.00')->assertJsonPath('passed', true)->assertJsonPath('course_completed', true);
        $this->assertSame('completed', $this->enrolmentOf($this->emp1, $c)->status);
    }

    public function test_attempts_stop_at_the_limit(): void
    {
        $c = $this->course('QUIZ-2');
        $a = $this->quiz($c);
        $this->assign($c, [$this->emp1])->assertCreated();
        $this->actAs($this->emp1);
        $this->postJson($this->p("assessments/$a/attempts"), ['answers' => ['q1' => [0]]])->assertCreated()->assertJsonPath('passed', false);
        $this->postJson($this->p("assessments/$a/attempts"), ['answers' => []])->assertCreated()->assertJsonPath('attempts_remaining', 0);
        $this->postJson($this->p("assessments/$a/attempts"), ['answers' => ['q1' => [1], 'q2' => [0, 1]]])->assertUnprocessable();
        $this->assertSame('in_progress', $this->enrolmentOf($this->emp1, $c)->status);
    }

    public function test_requires_enrolment_and_validates_the_answer_key(): void
    {
        $c = $this->course('QUIZ-3');
        $a = $this->quiz($c);
        $this->actAs($this->emp2);
        $this->getJson($this->p("courses/$c/assessment"))->assertForbidden();
        $this->postJson($this->p("assessments/$a/attempts"), ['answers' => ['q1' => [1]]])->assertUnprocessable();
        $this->signIn($this->admin);
        $this->postJson($this->p("courses/$c/assessments"), ['title' => 'Bad', 'questions' => [['text' => 'Q', 'options' => ['a', 'b'], 'correct' => [5]]]])->assertUnprocessable();
    }

    public function test_attendance_gate_keeps_course_open_after_a_passed_attempt(): void
    {
        $c = $this->course('QUIZ-4', ['min_attendance_pct' => 100]);
        $a = $this->quiz($c);
        $s = $this->makeSession($c, 'Q4-S', '2026-10-08', '2026-10-08');
        $this->assign($c, [$this->emp1], ['session_uuid' => $s])->assertCreated();
        $this->actAs($this->emp1);
        $r = $this->postJson($this->p("assessments/$a/attempts"), ['answers' => ['q1' => [1], 'q2' => [0, 1]]])->assertCreated();
        $this->assertTrue($r->json('passed'));
        $this->assertFalse($r->json('course_completed'));
        $this->assertNotEmpty($r->json('note'));
    }
}
