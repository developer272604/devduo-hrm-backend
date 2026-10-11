<?php

namespace App\Domain\Performance;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\People\Models\Employee;
use App\Domain\Performance\Models\{CalibrationChange, Cycle, Response, Review};
use App\Domain\Performance\Support\Num;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Calibration with maker-checker (proposer cannot approve), justification, history, fairness warnings, and finalization that freezes reviews. Humans decide; nothing is automatic. */
final class CalibrationService
{
    public function __construct(private PerformanceAccess $access, private ScoreEngine $engine, private DomainEvents $events) {}

    public function start(User $actor, Cycle $c): Cycle
    {
        abort_unless($actor->allows('performance.calibrate'), 403, 'You do not have access to this area.');
        if ($c->status !== 'open') throw ValidationException::withMessages(['status' => 'Only open cycles can enter calibration.']);
        DB::transaction(function () use ($c) {
            $c->update(['status' => 'calibration']);
            Review::where('cycle_id', $c->id)->where('status', 'in_progress')->update(['status' => 'calibration']);
            Audit::record('cycle.calibration_started', 'performance', 'cycle:'.$c->uuid);
        });
        // Pending reviewer tasks no longer apply.
        foreach (Response::whereIn('review_id', Review::where('cycle_id', $c->id)->select('id'))->where('status', 'pending')->pluck('uuid') as $u) app(TaskService::class)->completeBySource('performance', 'review-response:'.$u);

        return $c;
    }

    public function propose(User $actor, Review $r, string $toScore, string $justification): CalibrationChange
    {
        abort_unless($actor->allows('performance.calibrate'), 403, 'You do not have access to this area.');
        abort_unless($this->access->reaches($actor, $r->employee_id, 'performance.calibrate'), 403, 'You cannot calibrate this review.');
        if ($r->status !== 'calibration') throw ValidationException::withMessages(['review' => 'Rating changes are only possible during calibration.']);
        $scale = $r->cycle->template->scale;
        if (Decimal::cmp($toScore, Num::in($scale->min_value)) < 0 || Decimal::cmp($toScore, Num::in($scale->max_value)) > 0) throw ValidationException::withMessages(['to_score' => 'Score must be within the rating scale.']);
        if (mb_strlen(trim($justification)) < 10) throw ValidationException::withMessages(['justification' => 'Explain the reason for the change (at least 10 characters).']);
        if (CalibrationChange::where('review_id', $r->id)->where('status', 'pending')->exists()) throw ValidationException::withMessages(['review' => 'A change for this review is already waiting for a decision.']);
        $from = $r->final_score ?? $r->computed_score;
        $c = CalibrationChange::create(['review_id' => $r->id, 'from_score' => $from, 'to_score' => Num::round($toScore, 3), 'justification' => $justification, 'proposed_by' => $actor->id]);
        Audit::record('review.rating_change_proposed', 'performance', 'review:'.$r->uuid, ['from' => $from, 'to' => $c->to_score]);
        foreach (DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('users.tenant_id', $actor->tenant_id)->where('users.active', true)->where('users.id', '!=', $actor->id)->where('permissions.key', 'performance.calibrate.approve')->distinct()->pluck('users.id') as $uid) {
            app(TaskService::class)->createOnce(['title' => 'Approve a calibration change', 'description' => 'A rating change needs an independent decision.', 'assignee_id' => (int) $uid, 'source_module' => 'performance',
                'source_entity' => 'calibration:'.$c->uuid, 'action' => '/performance/calibration', 'status' => 'open', 'priority' => 'normal']);
        }

        return $c;
    }

    public function decide(User $actor, CalibrationChange $c, string $decision, ?string $note): CalibrationChange
    {
        abort_unless($actor->allows('performance.calibrate.approve'), 403, 'You do not have access to this area.');
        abort_if($c->proposed_by === $actor->id, 403, 'You cannot decide a change you proposed.');
        if ($c->status !== 'pending') throw ValidationException::withMessages(['status' => 'This change was already decided.']);
        return DB::transaction(function () use ($actor, $c, $decision, $note) {
            $r = Review::query()->whereKey($c->review_id)->lockForUpdate()->first();
            abort_unless($this->access->reaches($actor, $r->employee_id, 'performance.calibrate.approve'), 403, 'You cannot decide for this review.');
            if ($r->status !== 'calibration') throw ValidationException::withMessages(['review' => 'The review is no longer in calibration.']);
            $approve = $decision === 'approve';
            $c->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 500) : null]);
            if ($approve) {
                $scale = $r->cycle->template->scale;
                $r->update(['final_score' => $c->to_score, 'final_rating' => $this->engine->label($scale, Num::in($c->to_score))]);
            }
            app(TaskService::class)->completeBySource('performance', 'calibration:'.$c->uuid);
            Audit::record($approve ? 'review.rating_changed' : 'review.rating_change_rejected', 'performance', 'review:'.$r->uuid, ['from' => $c->from_score, 'to' => $c->to_score, 'proposed_by' => $c->proposed_by, 'decided_by' => $actor->id]);

            return $c;
        });
    }

    /** Finalizes ready reviews (manager response submitted, no pending change) and closes the cycle when nothing is left. */
    public function finalize(User $actor, Cycle $c): array
    {
        abort_unless($actor->allows('performance.calibrate.approve'), 403, 'You do not have access to this area.');
        if ($c->status !== 'calibration') throw ValidationException::withMessages(['status' => 'Start calibration before finalizing.']);
        $pending = CalibrationChange::where('status', 'pending')->whereIn('review_id', Review::where('cycle_id', $c->id)->select('id'))->count();
        if ($pending) throw ValidationException::withMessages(['status' => $pending.' calibration '.($pending === 1 ? 'change is' : 'changes are').' still waiting for a decision.']);
        $done = 0; $skipped = [];
        foreach (Review::where('cycle_id', $c->id)->where('status', 'calibration')->get() as $r) {
            $hasManager = Response::where('review_id', $r->id)->where('reviewer_type', 'manager')->where('status', 'submitted')->exists();
            $skipped = $hasManager && ($r->final_score !== null || $r->computed_score !== null) ? $skipped : [...$skipped, $r->uuid];
            if (! $hasManager || ($r->final_score === null && $r->computed_score === null)) continue;
            $this->finalizeOne($actor, $r, $c);
            $done++;
        }
        if (! Review::where('cycle_id', $c->id)->where('status', 'calibration')->exists()) $c->update(['status' => 'closed', 'closed_at' => now()]);
        Audit::record('cycle.finalized', 'performance', 'cycle:'.$c->uuid, ['finalized' => $done, 'skipped' => count($skipped)]);

        return ['finalized' => $done, 'not_ready' => $skipped, 'cycle_status' => $c->refresh()->status];
    }

    private function finalizeOne(User $actor, Review $r, Cycle $c): void
    {
        $scale = $c->template->scale;
        $score = $r->final_score ?? $r->computed_score;
        $changes = CalibrationChange::where('review_id', $r->id)->orderBy('id')->get(['uuid', 'from_score', 'to_score', 'status', 'justification', 'proposed_by', 'decided_by']);
        $snapshot = ['computed_score' => $r->computed_score, 'final_score' => Num::round(Num::in($score), 3), 'scale' => $scale->only(['code', 'min_value', 'max_value', 'labels']), 'trace' => $r->trace,
            'calibration' => $changes->toArray(), 'template' => $c->template->only(['uuid', 'name', 'sections', 'reviewer_weights']), 'frozen_at' => now()->toIso8601String()];
        $r->update(['final_score' => Num::round(Num::in($score), 3), 'final_rating' => $this->engine->label($scale, Num::in($score)), 'status' => 'finalized', 'finalized_at' => now(), 'finalized_by' => $actor->id, 'snapshot' => $snapshot]);
        $e = Employee::find($r->employee_id);
        if ($e && ($uid = $this->access->userIdOf($e))) app(NotificationService::class)->send($uid, 'Your performance review is complete', 'The result for "'.$c->name.'" is available to read and acknowledge.', 'performance', '/performance/reviews');
        $this->events->record('PerformanceReviewFinalized', ['employee' => $r->employee_id, 'subject' => ['performance_review', $r->id], 'summary' => 'Performance review for "'.$c->name.'" was finalized', 'payload' => ['review' => $r->uuid, 'cycle' => $c->uuid], 'classification' => Classification::HR_CONFIDENTIAL]);
        Audit::record('review.finalized', 'performance', 'review:'.$r->uuid);
    }

    /** Calibration workspace: scoped reviews, distribution, change history and fairness warnings. */
    public function workspace(User $actor, Cycle $c): array
    {
        abort_unless($actor->allows('performance.calibrate') || $actor->allows('performance.calibrate.approve'), 403, 'You do not have access to this area.');
        $reviews = Review::where('cycle_id', $c->id)->whereIn('employee_id', $this->access->reachIds($actor, 'performance.calibrate'))->get();
        $scores = $reviews->map(fn ($r) => $r->final_score ?? $r->computed_score)->filter(fn ($s) => $s !== null);
        $dist = [];
        foreach ($scores as $s) { $k = Num::round(Decimal::of(Num::in($s))->toScale(0, \Brick\Math\RoundingMode::HALF_UP), 0); $dist[$k] = ($dist[$k] ?? 0) + 1; }
        ksort($dist);
        $overall = $scores->count() ? Decimal::div(Decimal::sum($scores->map(fn ($s) => Num::in($s))), $scores->count(), 6) : null;
        $scale = $c->template->scale; $span = Decimal::of(Num::in($scale->max_value))->minus(Num::in($scale->min_value));
        $threshold = Decimal::mul($span, '0.15');
        $warnings = [];
        foreach (['department_id' => 'department', 'manager_employee_id' => 'manager'] as $col => $label) {
            foreach ($reviews->groupBy($col) as $key => $group) {
                $vals = $group->map(fn ($r) => $r->final_score ?? $r->computed_score)->filter(fn ($s) => $s !== null);
                if ($key === '' || $vals->count() < 3 || ! $overall) continue;
                $avg = Decimal::div(Decimal::sum($vals->map(fn ($s) => Num::in($s))), $vals->count(), 6);
                if ($avg->minus($overall)->abs()->isGreaterThan($threshold)) $warnings[] = ['group' => $label, 'size' => $vals->count(), 'average' => Num::round($avg, 2), 'overall' => Num::round($overall, 2),
                    'message' => 'The '.$label.' group average differs notably from the company average. Review for consistency; this is a prompt for discussion, not a finding.'];
            }
        }
        $changes = CalibrationChange::whereIn('review_id', $reviews->pluck('id'))->latest('id')->limit(100)->get();
        return ['cycle' => ['uuid' => $c->uuid, 'name' => $c->name, 'status' => $c->status], 'total' => $reviews->count(), 'distribution' => $dist, 'fairness_warnings' => $warnings,
            'reviews' => $reviews->map(fn ($r) => $this->row($r))->values()->all(),
            'changes' => $changes->map(fn ($x) => ['uuid' => $x->uuid, 'review_uuid' => $reviews->firstWhere('id', $x->review_id)?->uuid, 'from_score' => $x->from_score, 'to_score' => $x->to_score, 'justification' => $x->justification, 'status' => $x->status,
                'proposed_by_me' => $x->proposed_by === $actor->id, 'decided_at' => $x->decided_at?->toIso8601String()])->all()];
    }

    private function row(Review $r): array
    {
        $e = Employee::with('person')->find($r->employee_id);
        $m = Response::where('review_id', $r->id)->where('reviewer_type', 'manager')->where('status', 'submitted')->exists();

        return ['uuid' => $r->uuid, 'employee' => ['uuid' => $e->uuid, 'name' => trim(($e->person->legal_first_name ?? '').' '.($e->person->legal_last_name ?? ''))], 'status' => $r->status, 'computed_score' => $r->computed_score,
            'final_score' => $r->final_score, 'final_rating' => $r->final_rating, 'manager_submitted' => $m];
    }
}
