<?php

namespace App\Domain\Performance;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\People\Models\Employee;
use App\Domain\Performance\Models\{Competency, Cycle, Item, RatingScale, Response, Review};
use App\Domain\Performance\Support\Num;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Reviews: reviewer nominations, response submission with scoring trace, privacy-scoped presentation and employee acknowledgement. */
final class ReviewService
{
    public function __construct(private PerformanceAccess $access, private ScoreEngine $engine, private CycleService $cycles, private DomainEvents $events) {}

    /** Relation of the actor to the review: subject | hr | manager | reviewer | null. Highest applicable wins (subject is never also treated as manager). */
    public function role(User $u, Review $r): ?string
    {
        if ($this->access->isSelf($u, $r->employee_id) && $u->allows('performance.self')) return 'subject';
        if ($u->allows('performance.view') && $this->access->scope()->hasTenantWide($u, 'performance.view')) return 'hr';
        if ($u->allows('performance.view') && $this->access->scope()->canAccess($u, $r->employee_id, 'performance.view')) return 'manager';
        if (Response::where('review_id', $r->id)->where('reviewer_user_id', $u->id)->exists()) return 'reviewer';

        return null;
    }

    public function nominatePeers(User $actor, Review $r, array $employees): array
    {
        $cycle = $r->cycle; $role = $this->role($actor, $r);
        abort_unless(in_array($role, ['subject', 'hr', 'manager'], true), 403, 'You cannot nominate reviewers for this review.');
        if ($role === 'manager') abort_unless($actor->allows('performance.reviews.manage'), 403, 'You do not have access to this area.');
        if ($cycle->status !== 'open' || $r->status !== 'in_progress') throw ValidationException::withMessages(['review' => 'Reviewers can only be nominated while the review is in progress.']);
        if (Num::d($cycle->template->reviewer_weights['peer'] ?? 0)->isZero()) throw ValidationException::withMessages(['review' => 'This cycle does not collect peer feedback.']);
        $existing = Response::where('review_id', $r->id)->where('reviewer_type', 'peer')->pluck('reviewer_employee_id')->all();
        if (count($existing) + count($employees) > 8) throw ValidationException::withMessages(['employees' => 'A review can have at most 8 peer reviewers.']);
        $added = [];
        foreach ($employees as $p) {
            if ($p->id === $r->employee_id || $p->id === $r->manager_employee_id) throw ValidationException::withMessages(['employees' => 'The subject and manager cannot be nominated as peers.']);
            if (! $p->isWorking()) throw ValidationException::withMessages(['employees' => 'Peer reviewers must be active employees.']);
            if (in_array($p->id, $existing, true)) continue;
            $uid = $this->access->userIdOf($p);
            if (! $uid) throw ValidationException::withMessages(['employees' => 'Every peer reviewer needs a login.']);
            $resp = Response::create(['review_id' => $r->id, 'reviewer_type' => 'peer', 'reviewer_employee_id' => $p->id, 'reviewer_user_id' => $uid, 'due_on' => ($cycle->peer_due ?? $cycle->end_date)->toDateString()]);
            $this->cycles->task($resp, $r, $cycle);
            $added[] = $resp;
        }
        Audit::record('review.peers_nominated', 'performance', 'review:'.$r->uuid, ['count' => count($added)]);

        return $added;
    }

    public function reassignManager(User $actor, Review $r, Employee $manager): Review
    {
        abort_unless($actor->allows('performance.cycles.manage'), 403, 'You do not have access to this area.');
        if ($r->status !== 'in_progress') throw ValidationException::withMessages(['review' => 'The reviewer can only change while the review is in progress.']);
        $resp = Response::where('review_id', $r->id)->where('reviewer_type', 'manager')->first();
        if ($resp && $resp->status === 'submitted') throw ValidationException::withMessages(['review' => 'The manager review is already submitted.']);
        if ($manager->id === $r->employee_id) throw ValidationException::withMessages(['manager' => 'A person cannot review themselves as manager.']);
        DB::transaction(function () use ($r, $manager, $resp, $actor) {
            $old = $r->manager_employee_id;
            $r->update(['manager_employee_id' => $manager->id]);
            if ($resp) {
                app(TaskService::class)->completeBySource('performance', 'review-response:'.$resp->uuid);
                $resp->update(['reviewer_employee_id' => $manager->id, 'reviewer_user_id' => $this->access->userIdOf($manager)]);
                $this->cycles->task($resp, $r, $r->cycle);
            }
            Audit::record('review.manager_changed', 'performance', 'review:'.$r->uuid, ['from' => $old, 'to' => $manager->id, 'by' => $actor->id]);
        });

        return $r;
    }

    public function submit(User $actor, Response $resp, array $items, ?string $comments): Response
    {
        abort_unless($resp->reviewer_user_id === $actor->id, 403, 'This response is assigned to someone else.');
        return DB::transaction(function () use ($actor, $resp, $items, $comments) {
            $review = Review::query()->whereKey($resp->review_id)->lockForUpdate()->first();
            $cycle = $review->cycle; $tpl = $cycle->template; $scale = $tpl->scale;
            if ($cycle->status !== 'open' || $review->status !== 'in_progress') throw ValidationException::withMessages(['review' => 'This review is no longer accepting responses.']);
            if ($resp->status === 'submitted') throw ValidationException::withMessages(['review' => 'This response was already submitted.']);
            $sections = collect($tpl->sections)->keyBy('key');
            $rows = [];
            foreach ($items as $n => $i) {
                $sec = $sections[$i['section']] ?? null;
                if (! $sec) throw ValidationException::withMessages(["items.$n.section" => 'Unknown template section.']);
                $rating = Num::in($i['rating']);
                if (Decimal::cmp($rating, Num::in($scale->min_value)) < 0 || Decimal::cmp($rating, Num::in($scale->max_value)) > 0) throw ValidationException::withMessages(["items.$n.rating" => 'Rating must be between '.Num::round(Num::in($scale->min_value), 2).' and '.Num::round(Num::in($scale->max_value), 2).'.']);
                $label = $i['label'] ?? $i['ref'];
                if (($sec['type'] ?? '') === 'competencies') {
                    $comp = Competency::where('code', $i['ref'])->where('active', true)->first();
                    if (! $comp) throw ValidationException::withMessages(["items.$n.ref" => 'Unknown competency.']);
                    $label = $comp->name;
                }
                $rows[] = ['section' => $i['section'], 'ref' => (string) $i['ref'], 'label' => mb_substr((string) $label, 0, 200), 'weight' => Num::round(Num::in($i['weight'] ?? 1), 2), 'rating' => Num::round($rating, 2), 'comment' => $i['comment'] ?? null];
            }
            foreach ($sections as $k => $sec) if (! empty($sec['mandatory']) && ! collect($rows)->contains('section', $k)) throw ValidationException::withMessages(['items' => 'Section "'.($sec['title'] ?? $k).'" is mandatory.']);
            if (! $rows) throw ValidationException::withMessages(['items' => 'Rate at least one item.']);
            foreach ($rows as $row) Item::create($row + ['response_id' => $resp->id]);
            [$score] = $this->engine->responseScore($tpl, array_map(fn ($r) => (object) $r, $rows));
            $resp->update(['status' => 'submitted', 'score' => $score, 'comments' => $comments, 'submitted_at' => now()]);
            $this->recompute($review);
            app(TaskService::class)->completeBySource('performance', 'review-response:'.$resp->uuid);
            Audit::record('review.response_submitted', 'performance', 'review:'.$review->uuid, ['type' => $resp->reviewer_type]);
            if (in_array($resp->reviewer_type, ['self', 'manager'], true)) $this->events->record('PerformanceReviewSubmitted', ['employee' => $review->employee_id, 'subject' => ['performance_review', $review->id], 'summary' => ucfirst($resp->reviewer_type).' review submitted',
                'payload' => ['review' => $review->uuid, 'type' => $resp->reviewer_type], 'classification' => Classification::HR_CONFIDENTIAL]);

            return $resp->refresh();
        });
    }

    public function recompute(Review $review): void
    {
        if ($review->status !== 'in_progress') return;
        [$score, $trace] = $this->engine->reviewScore($review, $review->cycle, $review->cycle->template);
        $review->update(['computed_score' => $score, 'trace' => $trace]);
    }

    public function acknowledge(User $actor, Review $r, ?string $comment): Review
    {
        abort_unless($this->role($actor, $r) === 'subject', 403, 'Only the reviewed employee can acknowledge the review.');
        if ($r->status !== 'finalized') throw ValidationException::withMessages(['review' => $r->status === 'acknowledged' ? 'You already acknowledged this review.' : 'The review is not finalized yet.']);
        $r->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'employee_comment' => $comment]);
        Audit::record('review.acknowledged', 'performance', 'review:'.$r->uuid);

        return $r;
    }

    public function header(Review $r): array
    {
        $e = Employee::with('person')->find($r->employee_id);

        return ['uuid' => $r->uuid, 'cycle' => ['uuid' => $r->cycle->uuid, 'name' => $r->cycle->name, 'status' => $r->cycle->status], 'status' => $r->status,
            'employee' => ['uuid' => $e->uuid, 'name' => trim(($e->person->legal_first_name ?? '').' '.($e->person->legal_last_name ?? ''))]];
    }

    /** Full review for one viewer: content and identities are filtered by relation, release state and the cycle's anonymity settings. */
    public function present(User $u, Review $r): array
    {
        $role = $this->role($u, $r); abort_unless($role, 404);
        $cycle = $r->cycle; $settings = $cycle->settings ?? []; $scale = $cycle->template->scale;
        $final = in_array($r->status, Review::FINAL, true);
        $out = $this->header($r) + [
            'role' => $role,
            'scale' => ['min' => Num::round(Num::in($scale->min_value), 2), 'max' => Num::round(Num::in($scale->max_value), 2), 'labels' => $scale->labels],
            'template' => ['name' => $cycle->template->name, 'sections' => $cycle->template->sections,
                'competencies' => Competency::where('active', true)->orderBy('name')->get(['code', 'name'])->all()],
        ];
        $responses = Response::where('review_id', $r->id)->with('items')->orderBy('id')->get();
        $minPeers = max(1, (int) ($settings['min_peer_responses'] ?? 1));
        $anonymous = $settings['peer_anonymous'] ?? true;
        $reveal = $u->allows('performance.feedback.reveal');
        $peerSubmitted = $responses->where('reviewer_type', 'peer')->where('status', 'submitted')->count();
        $list = [];
        foreach ($responses as $x) {
            $own = $x->reviewer_user_id === $u->id;
            $row = ['uuid' => $x->uuid, 'type' => $x->reviewer_type, 'status' => $x->status, 'due_on' => $x->due_on?->toDateString(), 'mine' => $own];
            $show = match ($role) {
                'subject' => $own || ($final && ($x->reviewer_type === 'manager' || ($x->reviewer_type === 'peer' && ! empty($settings['share_peer_feedback'])))),
                'reviewer' => $own,
                'manager' => $x->reviewer_type !== 'skip' || $own,
                'hr' => true,
            };
            if ($x->reviewer_type === 'peer' && ! $own && $peerSubmitted < $minPeers) $show = false; // too few responses to protect reviewers
            if ($show && $x->status === 'submitted') {
                $row['score'] = $role === 'subject' && ! $final && ! $own ? null : $x->score;
                $row['comments'] = $x->comments;
                $row['items'] = $x->items->map(fn ($i) => ['section' => $i->section, 'label' => $i->label, 'weight' => Num::round(Num::in($i->weight), 2), 'rating' => Num::round(Num::in($i->rating), 2), 'comment' => $i->comment])->all();
            } elseif ($x->status === 'submitted') { $row['hidden'] = true; }
            $showName = $x->reviewer_type !== 'peer' || ! $anonymous || $reveal || $own;
            $row['reviewer'] = $showName && $x->reviewer_employee_id && $show ? $this->name(Employee::with('person')->find($x->reviewer_employee_id)) : null;
            if ($role === 'subject' && ! $own && $x->reviewer_type !== 'manager') $row['reviewer'] = null;
            $list[] = $row;
        }
        $out['responses'] = $list;
        if ($role === 'subject') {
            $out['result'] = $final ? ['final_score' => $r->final_score, 'final_rating' => $r->final_rating, 'finalized_at' => $r->finalized_at?->toIso8601String(), 'acknowledged_at' => $r->acknowledged_at?->toIso8601String()] : null;
        } elseif (in_array($role, ['manager', 'hr'], true)) {
            $out['computed_score'] = $r->computed_score; $out['final_score'] = $r->final_score; $out['final_rating'] = $r->final_rating; $out['trace'] = $r->trace;
            $out['finalized_at'] = $r->finalized_at?->toIso8601String(); $out['peer_responses'] = $peerSubmitted;
        }
        Audit::record('review.viewed', 'performance', 'review:'.$r->uuid, ['role' => $role]);

        return $out;
    }

    private function name(?Employee $e): ?string
    {
        return $e ? trim(($e->person->legal_first_name ?? '').' '.($e->person->legal_last_name ?? '')) : null;
    }
}
