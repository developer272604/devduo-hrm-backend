<?php

namespace App\Domain\Work;

use App\Domain\Audit\Audit;
use App\Domain\Notifications\NotificationService;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;

final class TaskService
{
    /** Future modules call this service inside an established tenant context. */
    public function create(array $data): WorkItem
    {
        abort_unless(User::where('tenant_id', app(TenantContext::class)->id)->where('active', true)->whereKey($data['assignee_id'])->exists(), 422, 'Choose an active user in this workspace.');

        return DB::transaction(function () use ($data) {
            $task = WorkItem::create($data);
            app(NotificationService::class)->send($task->assignee_id, 'A task is ready for you', $task->title, 'work', '/my-work');
            Audit::record('created', 'work', 'task:'.$task->id);

            return $task;
        });
    }

    /**
     * Creates a My Work task unless an open task for the same assignee and source already exists
     * (idempotent: schedulers and event listeners may call this repeatedly without duplicating work).
     */
    public function createOnce(array $data): ?WorkItem
    {
        $exists = WorkItem::query()->where('assignee_id', $data['assignee_id'])->where('source_module', $data['source_module'])->where('source_entity', $data['source_entity'] ?? null)
            ->where('status', 'open')->exists();

        return $exists ? null : $this->create($data);
    }

    /** Completes every open task created for a source entity (e.g. after the underlying item is resolved). */
    public function completeBySource(string $module, string $entity): int
    {
        return WorkItem::query()->where('source_module', $module)->where('source_entity', $entity)->where('status', 'open')->update(['status' => 'completed', 'completed_at' => now()]);
    }
}
