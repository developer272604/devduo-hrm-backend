<?php

namespace App\Domain\Performance;

use App\Domain\Notifications\NotificationService;
use App\Domain\Performance\Models\{Cycle, PipCheckpoint, Response, Review};
use App\Domain\Tenancy\TenantClock;

/** Scheduled reminders. Idempotent: each response / checkpoint is reminded at most once per day (reminded_at stamp) and My Work tasks use createOnce. */
final class PerformanceJobs
{
    public function __construct(private CycleService $cycles) {}

    public function remind(): array
    {
        $today = TenantClock::today(); $soon = now()->parse($today)->addDays(3)->toDateString();
        $out = ['responses' => 0, 'checkpoints' => 0];
        $openCycles = Cycle::where('status', 'open')->pluck('id');
        $rows = Response::where('status', 'pending')->whereNotNull('reviewer_user_id')->whereNotNull('due_on')->where('due_on', '<=', $soon)
            ->whereIn('review_id', Review::whereIn('cycle_id', $openCycles)->where('status', 'in_progress')->select('id'))
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', $today.' 00:00:00'))->get();
        foreach ($rows as $r) {
            $overdue = $r->due_on->toDateString() < $today;
            app(NotificationService::class)->send($r->reviewer_user_id, $overdue ? 'A review is overdue' : 'A review is due soon', 'Due '.$r->due_on->toDateString().'. Open Performance to complete it.', 'performance', '/performance/reviews');
            $review = Review::find($r->review_id);
            $this->cycles->task($r, $review, $review->cycle);
            $r->update(['reminded_at' => now()]);
            $out['responses']++;
        }
        foreach (PipCheckpoint::where('status', 'pending')->where('due_on', '<=', $soon)->whereIn('pip_id', \App\Domain\Performance\Models\Pip::where('status', 'active')->select('id'))
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<', $today.' 00:00:00'))->get() as $c) {
            $pip = \App\Domain\Performance\Models\Pip::find($c->pip_id);
            if ($pip->created_by && \App\Models\User::whereKey($pip->created_by)->where('active', true)->exists()) {
                app(\App\Domain\Work\TaskService::class)->createOnce(['title' => 'Record an improvement plan checkpoint', 'description' => 'A checkpoint is due '.$c->due_on->toDateString().'.', 'assignee_id' => $pip->created_by,
                    'source_module' => 'performance', 'source_entity' => 'pip-checkpoint:'.$c->uuid, 'action' => '/performance/pip', 'status' => 'open', 'priority' => 'normal']);
            }
            $c->update(['reminded_at' => now()]);
            $out['checkpoints']++;
        }

        return $out;
    }
}
