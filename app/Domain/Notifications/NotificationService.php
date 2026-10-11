<?php

namespace App\Domain\Notifications;

use App\Domain\Experience\CommunicationPreferenceService;
use App\Domain\Tenancy\TenantContext;
use App\Models\InAppNotification;
use App\Models\Tenant;
use App\Models\User;

final class NotificationService
{
    public function send(int $userId, string $title, string $body, string $category, ?string $action = null, bool $essential = false): ?InAppNotification
    {
        $id = app(TenantContext::class)->id;
        abort_unless($id, 422, 'Tenant context is required.');
        abort_unless(User::where('tenant_id', $id)->whereKey($userId)->exists(), 422, 'Recipient must belong to this workspace.');
        if (! $essential && in_array($category, ['company', 'experience.ticket', 'experience.announcement', 'experience.survey', 'experience.recognition', 'experience.event'], true)
            && ! (Tenant::findOrFail($id)->settings['notifications'] ?? true)) {
            return null;
        }
        if (! $essential && ! app(CommunicationPreferenceService::class)->allowsInApp($userId, $category)) return null;
        if ($action && (! preg_match('#^/(?!/)#', $action) || str_contains($action, '\\'))) {
            throw new \InvalidArgumentException('Notification actions must be local paths.');
        }

        return InAppNotification::create(['user_id' => $userId, 'title' => $title, 'body' => $body, 'category' => $category, 'action' => $action]);
    }
}
