<?php

namespace App\Domain\Experience;

use App\Domain\Experience\Models\EmployeeMilestoneDelivery;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Notifications\NotificationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EmployeeMilestoneService
{
    public function __construct(private NotificationService $notifications) {}

    public function notifyToday(): int
    {
        $date = TenantClock::today();
        $monthDay = substr($date, 5);
        $tenantId = app(TenantContext::class)->id;
        $activeUserIds = User::where('tenant_id', $tenantId)->where('active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $delivered = 0;
        Employee::with('person')->whereIn('status', Employee::WORKING)->whereNull('deleted_at')->orderBy('id')->chunkById(250, function ($employees) use ($date, $monthDay, $activeUserIds, &$delivered) {
            foreach ($employees as $employee) {
                $userId = $employee->person?->user_id;
                if (! $userId || ! in_array((int) $userId, $activeUserIds, true)) continue;
                $birthDate = $employee->person?->date_of_birth?->format('m-d');
                if ($birthDate === $monthDay && $this->sendOnce($employee, (int) $userId, 'birthday', $date, 'Happy birthday!', 'Wishing you a wonderful birthday.')) $delivered++;
                $startDate = ($employee->original_joining_date ?? $employee->joined_on)?->format('Y-m-d');
                if ($startDate && substr($startDate, 5) === $monthDay && (int) substr($date, 0, 4) > (int) substr($startDate, 0, 4)
                    && $this->sendOnce($employee, (int) $userId, 'work_anniversary', $date, 'Happy work anniversary', 'Thank you for all you contribute.')) $delivered++;
            }
        });
        return $delivered;
    }

    private function sendOnce(Employee $employee, int $userId, string $type, string $date, string $title, string $body): bool
    {
        return DB::transaction(function () use ($employee, $userId, $type, $date, $title, $body) {
            $delivery = EmployeeMilestoneDelivery::firstOrCreate(['user_id' => $userId, 'milestone_type' => $type, 'milestone_date' => $date], ['employee_id' => $employee->id]);
            if ($delivery->sent_at || $delivery->suppressed_at) return false;
            $notification = $this->notifications->send($userId, $title, $body, 'experience.event', '/experience/preferences');
            $delivery->update($notification ? ['sent_at' => now()] : ['suppressed_at' => now()]);
            return (bool) $notification;
        });
    }
}
