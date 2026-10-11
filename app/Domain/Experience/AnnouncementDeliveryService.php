<?php

namespace App\Domain\Experience;

use App\Domain\Audit\Audit;
use App\Domain\Experience\Models\{Announcement, AnnouncementDelivery};
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

final class AnnouncementDeliveryService
{
    public function __construct(private NotificationService $notifications) {}

    public function deliverDue(): int
    {
        $due = Announcement::where('status', 'published')->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', TenantClock::today()))
            ->whereNull('notifications_sent_at')->orderBy('id')->limit(200)->get();
        $processed = 0;
        foreach ($due as $announcement) {
            $userIds = $this->recipientUserIds($announcement);
            foreach ($userIds as $userId) {
                DB::transaction(function () use ($announcement, $userId) {
                    $delivery = AnnouncementDelivery::firstOrCreate(['announcement_id' => $announcement->id, 'user_id' => $userId]);
                    if ($delivery->sent_at || $delivery->suppressed_at) return;
                    $category = ($announcement->communication_type ?? '') === 'event' ? 'experience.event' : 'experience.announcement';
                    $title = ($announcement->communication_type ?? '') === 'event' ? 'Employee event' : 'Employee announcement';
                    $notification = $this->notifications->send($userId, $title, 'A new employee update is available.', $category, '/experience/announcements', $announcement->acknowledgment_required);
                    $delivery->update($notification ? ['sent_at' => now()] : ['suppressed_at' => now()]);
                });
            }
            $announcement->update(['notifications_sent_at' => now()]);
            Audit::record('announcement.notifications_processed', 'experience', 'announcement:'.$announcement->uuid, ['recipient_count' => count($userIds)]);
            $processed++;
        }
        return $processed;
    }

    private function recipientUserIds(Announcement $announcement): array
    {
        $query = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')
            ->join('users', function ($join) { $join->on('users.id', '=', 'people.user_id')->on('users.tenant_id', '=', 'employees.tenant_id')->where('users.active', true); })
            ->where('employees.tenant_id', app(TenantContext::class)->id)->whereIn('employees.status', Employee::WORKING)->whereNull('employees.deleted_at');
        if (! $announcement->audience_all) {
            $query->whereIn('employees.id', DB::table('experience_announcement_recipients')->where('announcement_id', $announcement->id)->select('employee_id'));
        }
        return $query->distinct()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
    }
}
