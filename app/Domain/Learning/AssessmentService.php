<?php

namespace App\Domain\Learning;

use App\Domain\Audit\Audit;
use App\Domain\Learning\Models\{Assessment, Attempt, Course, Enrolment};
use App\Domain\Learning\Support\Money;
use App\Models\User;
use Brick\Math\{BigDecimal, RoundingMode};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Quiz delivery and server-side scoring. Correct answers never leave the server for takers; results reveal per-question correctness only after submission. */
final class AssessmentService
{
    public function __construct(private LearningAccess $access, private EnrolmentService $enrolments) {}

    /** Author view (includes the answer key). */
    public function presentFull(Assessment $a): array
    {
        return ['uuid' => $a->uuid, 'title' => $a->title, 'pass_percent' => $a->pass_percent, 'max_attempts' => $a->max_attempts, 'active' => (bool) $a->active, 'questions' => $a->questions];
    }

    /** Taker view: no `correct` key anywhere. */
    public function presentForTaker(Assessment $a, int $employeeId): array
    {
        $used = Attempt::where('assessment_id', $a->id)->where('employee_id', $employeeId)->count();
        $last = Attempt::where('assessment_id', $a->id)->where('employee_id', $employeeId)->latest('id')->first();

        return ['uuid' => $a->uuid, 'title' => $a->title, 'pass_percent' => $a->pass_percent, 'max_attempts' => $a->max_attempts, 'attempts_used' => $used,
            'attempts_remaining' => $a->max_attempts === null ? null : max(0, $a->max_attempts - $used), 'last_attempt' => $last ? ['percent' => $last->percent, 'passed' => (bool) $last->passed, 'attempt_no' => $last->attempt_no] : null,
            'questions' => array_map(fn ($q) => ['id' => $q['id'], 'text' => $q['text'], 'options' => $q['options'], 'points' => $q['points'], 'multiple' => count($q['correct']) > 1], $a->questions)];
    }

    public function activeFor(Course $c): ?Assessment
    {
        return Assessment::where('course_id', $c->id)->where('active', true)->latest('id')->first();
    }

    /** @param array<string,array<int,int>|int> $answers question id => selected option indexes */
    public function submit(User $actor, Assessment $a, int $employeeId, array $answers): array
    {
        abort_unless($this->access->isSelf($actor, $employeeId) && $actor->allows('learning.self'), 403, 'You can only take your own assessments.');

        return DB::transaction(function () use ($a, $employeeId, $answers) {
            $e = Enrolment::where('employee_id', $employeeId)->where('course_id', $a->course_id)->whereIn('status', ['enrolled', 'in_progress'])->lockForUpdate()->first();
            if (! $e) throw ValidationException::withMessages(['assessment' => 'Enrol in the course before taking its assessment.']);
            $used = Attempt::where('assessment_id', $a->id)->where('employee_id', $employeeId)->count();
            if ($a->max_attempts !== null && $used >= $a->max_attempts) throw ValidationException::withMessages(['assessment' => 'You have used all '.$a->max_attempts.' attempts.']);
            $score = BigDecimal::zero(); $max = BigDecimal::zero(); $detail = [];
            foreach ($a->questions as $q) {
                $given = array_values(array_unique(array_map('intval', (array) ($answers[$q['id']] ?? []))));
                $want = array_values(array_map('intval', $q['correct'])); sort($given); sort($want);
                $ok = $given === $want; $pts = BigDecimal::of((string) $q['points']);
                $max = $max->plus($pts); if ($ok) $score = $score->plus($pts);
                $detail[$q['id']] = $ok;
            }
            $percent = $max->isZero() ? '0.00' : (string) $score->multipliedBy(100)->dividedBy($max, 2, RoundingMode::HALF_UP);
            $passed = ! Money::gt($a->pass_percent, $percent);
            $attempt = Attempt::create(['assessment_id' => $a->id, 'enrolment_id' => $e->id, 'employee_id' => $employeeId, 'score' => (string) $score->toScale(2), 'max_score' => (string) $max->toScale(2),
                'percent' => $percent, 'passed' => $passed, 'answers' => $answers, 'attempt_no' => $used + 1]);
            Audit::record('assessment.attempted', 'learning', 'assessment:'.$a->uuid, ['attempt' => $used + 1, 'passed' => $passed]);
            $note = null; $completed = false;
            if ($e->status === 'enrolled') $e->update(['status' => 'in_progress']);
            if ($passed) {
                try { $this->enrolments->finish($e->refresh(), true, $percent); $completed = true; } catch (ValidationException $ex) { $e->update(['score' => $percent]); $note = collect($ex->errors())->flatten()->first(); }
            } else $e->update(['score' => $percent]);

            return ['attempt_no' => $attempt->attempt_no, 'score' => $attempt->score, 'max_score' => $attempt->max_score, 'percent' => $percent, 'passed' => $passed, 'results' => $detail,
                'attempts_remaining' => $a->max_attempts === null ? null : max(0, $a->max_attempts - ($used + 1)), 'course_completed' => $completed, 'note' => $note];
        });
    }
}
