<?php

namespace App\Domain\Performance;

use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\Performance\Models\{Review, ReviewAppeal};
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Employee appeals for finalized reviews. The review snapshot remains immutable; HR records the appeal outcome separately. */
final class ReviewAppealService
{
    public function __construct(private PerformanceAccess $access) {}

    public function request(User $actor, Review $review, string $reason): ReviewAppeal
    {
        abort_unless($this->access->isSelf($actor, $review->employee_id) && $actor->allows('performance.self'), 403, 'Only the reviewed employee can appeal this review.');
        if (! in_array($review->status, Review::FINAL, true)) throw ValidationException::withMessages(['review' => 'Only finalized reviews can be appealed.']);
        if (mb_strlen(trim($reason)) < 20) throw ValidationException::withMessages(['reason' => 'Explain the appeal reason in at least 20 characters.']);
        if (ReviewAppeal::where('review_id', $review->id)->where('status', 'pending')->exists()) throw ValidationException::withMessages(['review' => 'An appeal is already waiting for a decision.']);

        return DB::transaction(function () use ($actor, $review, $reason) {
            $appeal = ReviewAppeal::create(['review_id' => $review->id, 'status' => 'pending', 'reason' => $reason, 'requested_by' => $actor->id]);
            Audit::record('review.appeal_requested', 'performance', 'review:'.$review->uuid, ['appeal' => $appeal->uuid]);
            foreach ($this->decisionUserIds($actor, $review) as $uid) {
                app(TaskService::class)->createOnce(['title' => 'Decide a performance review appeal', 'description' => 'A finalized review appeal needs HR review.', 'assignee_id' => $uid, 'source_module' => 'performance',
                    'source_entity' => 'review-appeal:'.$appeal->uuid, 'action' => '/performance/reviews/appeals', 'status' => 'open', 'priority' => 'normal']);
            }

            return $appeal;
        });
    }

    public function decide(User $actor, ReviewAppeal $appeal, string $decision, ?string $note): ReviewAppeal
    {
        abort_unless($actor->allows('performance.calibrate.approve') || $actor->allows('performance.reviews.manage'), 403, 'You do not have access to this area.');
        abort_if($appeal->requested_by === $actor->id, 403, 'You cannot decide your own appeal.');
        if ($appeal->status !== 'pending') throw ValidationException::withMessages(['status' => 'This appeal was already decided.']);
        if (! in_array($decision, ['approve', 'reject'], true)) throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        if (mb_strlen(trim((string) $note)) < 10) throw ValidationException::withMessages(['note' => 'Add a decision note of at least 10 characters.']);

        return DB::transaction(function () use ($actor, $appeal, $decision, $note) {
            $review = Review::query()->whereKey($appeal->review_id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canDecide($actor, $review), 403, 'You cannot decide this appeal.');
            $approved = $decision === 'approve';
            $appeal->update(['status' => $approved ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            app(TaskService::class)->completeBySource('performance', 'review-appeal:'.$appeal->uuid);
            Audit::record($approved ? 'review.appeal_approved' : 'review.appeal_rejected', 'performance', 'review:'.$review->uuid, ['appeal' => $appeal->uuid, 'decided_by' => $actor->id]);

            return $appeal->refresh();
        });
    }

    public function list(User $actor, array $filters = []): array
    {
        abort_unless($actor->allows('performance.calibrate.approve') || $actor->allows('performance.reviews.manage'), 403, 'You do not have access to this area.');
        $ids = $this->access->reachIds($actor, $actor->allows('performance.calibrate.approve') ? 'performance.calibrate.approve' : 'performance.reviews.manage');
        $q = ReviewAppeal::with('review.cycle')->whereIn('review_id', Review::whereIn('employee_id', $ids)->select('id'))->latest('id');
        if (! empty($filters['status'])) $q->where('status', $filters['status']);

        return $q->limit(min(100, max(1, (int) ($filters['limit'] ?? 50))))->get()->map(fn ($a) => $this->present($actor, $a))->all();
    }

    public function present(User $viewer, ReviewAppeal $appeal): array
    {
        $review = $appeal->review()->with('cycle')->first();
        $employee = Employee::with('person')->find($review->employee_id);
        $isSubject = $this->access->isSelf($viewer, $review->employee_id);
        $canDecide = $appeal->status === 'pending' && $appeal->requested_by !== $viewer->id && $this->canDecide($viewer, $review);
        abort_unless($isSubject || $canDecide || $this->canSee($viewer, $review), 404);

        return ['uuid' => $appeal->uuid, 'status' => $appeal->status, 'review_uuid' => $review->uuid, 'review_status' => $review->status,
            'cycle' => ['uuid' => $review->cycle->uuid, 'name' => $review->cycle->name],
            'employee' => ['uuid' => $employee?->uuid, 'name' => $employee ? trim(($employee->person->legal_first_name ?? '').' '.($employee->person->legal_last_name ?? '')) : null],
            'reason' => $appeal->reason, 'decision_note' => $appeal->decision_note, 'requested_by_me' => $appeal->requested_by === $viewer->id,
            'can_decide' => $canDecide, 'decided_at' => $appeal->decided_at?->toIso8601String(), 'created_at' => $appeal->created_at?->toIso8601String()];
    }

    private function canDecide(User $actor, Review $review): bool
    {
        return $this->access->reaches($actor, $review->employee_id, 'performance.calibrate.approve')
            || $this->access->reaches($actor, $review->employee_id, 'performance.reviews.manage');
    }

    private function canSee(User $actor, Review $review): bool
    {
        return $this->canDecide($actor, $review) || $this->access->reaches($actor, $review->employee_id, 'performance.view');
    }

    /** @return array<int,int> */
    private function decisionUserIds(User $actor, Review $review): array
    {
        return DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('users.tenant_id', $actor->tenant_id)->where('users.active', true)->where('users.id', '!=', $actor->id)->whereIn('permissions.key', ['performance.calibrate.approve', 'performance.reviews.manage'])
            ->distinct()->pluck('users.id')->map(fn ($id) => (int) $id)->filter(function (int $id) use ($review) {
                $u = User::find($id);

                return $u && $this->canDecide($u, $review);
            })->values()->all();
    }
}
