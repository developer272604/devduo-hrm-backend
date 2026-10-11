<?php

namespace App\Domain\Recruitment;

use App\Domain\Notifications\NotificationService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\User;

/** Thin helper over the shared My Work + Notifications services (never a second task engine). */
final class RecruitmentNotify
{
    public static function task(int $assignee, string $title, string $description, string $entity, string $action = '/recruitment', string $priority = 'normal'): void
    {
        app(TaskService::class)->createOnce(['title' => $title, 'description' => $description, 'assignee_id' => $assignee, 'source_module' => 'recruitment', 'source_entity' => $entity, 'action' => $action, 'status' => 'open', 'priority' => $priority]);
    }

    public static function tell(int $user, string $title, string $body, string $action = '/recruitment'): void
    {
        app(NotificationService::class)->send($user, $title, $body, 'recruitment', $action);
    }

    /** Active users holding a permission, excluding one user (maker-checker). */
    public static function holders(string $permission, ?int $except = null)
    {
        return User::where('tenant_id', app(TenantContext::class)->id)->where('active', true)->when($except, fn ($q) => $q->where('id', '!=', $except))->whereHas('roles.permissions', fn ($q) => $q->where('key', $permission))->get();
    }
}
