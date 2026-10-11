<?php

namespace App\Console\Commands;

use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Models\Regularization;
use App\Domain\Attendance\Requests\ApproverResolver;
use App\Domain\Attendance\Requests\RequestRules;
use App\Domain\Attendance\Requests\RequestSupport;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantRunner;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reminders as notifications/My Work tasks, deduplicated per (user, key) and held back during quiet hours (a held notice is retried next run).
 * Recommended schedule: every 15 minutes. Covers missing punch / check-out, regularization deadline, shift starting soon, pending-approval nudges.
 */
class AttendanceSendReminders extends Command
{
    protected $signature = 'attendance:send-reminders';

    protected $description = 'Send attendance reminders (missing punch, regularization deadline, shift starting soon, pending approvals)';

    public function handle(TenantRunner $runner, RequestSupport $support, ApproverResolver $approvers): int
    {
        foreach (Tenant::query()->pluck('id') as $tenantId) {
            $n = $runner->run($tenantId, fn () => $this->tenant($support, $approvers));
            $this->line("Tenant $tenantId: $n reminder(s).");
        }

        return self::SUCCESS;
    }

    private function tenant(RequestSupport $support, ApproverResolver $approvers): int
    {
        $sent = 0;
        $today = TenantClock::today();
        $deadlineDays = (int) RequestRules::setting('cutoffs.regularization_days_after', 5);
        $afterHours = (int) RequestRules::setting('notifications.missing_punch_after_hours', 4);

        // Missing punches: yesterday and today (once the shift end + threshold has passed the day is flagged by the processor).
        $days = DB::table('attendance_days')->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('missing_punch', true)
            ->where('attendance_date', '>=', CarbonImmutable::parse($today)->subDays($deadlineDays)->toDateString())->where('attendance_date', '<=', $today)->get();
        foreach ($days as $d) {
            $e = Employee::query()->find($d->employee_id);
            $uid = $e ? $approvers->userIdOf($e) : null;
            if (! $e || ! $uid || $e->timezone === null) {
                continue;
            }
            $left = CarbonImmutable::parse($d->attendance_date)->addDays($deadlineDays)->diffInDays(CarbonImmutable::parse($today), true);
            $support->assignTasks([$uid], "Resolve missing punch: {$d->attendance_date}", 'Your attendance is missing a punch. Submit a regularization.', "missing_punch:{$e->id}:{$d->attendance_date}", '/attendance?tab=regularization', null, ['kind' => 'missing_punch']);
            $sent += $support->notify($uid, 'Missing punch', "A punch is missing on {$d->attendance_date}.", "missing:{$e->id}:{$d->attendance_date}", '/attendance?tab=regularization', true, $e->timezone) ? 1 : 0;
            if ($left <= 1) {
                $sent += $support->notify($uid, 'Regularization deadline', "The last day to regularize {$d->attendance_date} is close.", "deadline:{$e->id}:{$d->attendance_date}", '/attendance?tab=regularization', true, $e->timezone) ? 1 : 0;
            }
        }

        // Shift starting soon (published schedule via ScheduleResolver, when bound).
        if (app()->bound(ScheduleResolver::class)) {
            $minutes = (int) RequestRules::setting('notifications.shift_starting_soon_minutes', 60);
            foreach (Employee::query()->whereIn('status', Employee::WORKING)->limit(2000)->get() as $e) {
                try {
                    $s = app(ScheduleResolver::class)->resolve($e->id, TenantClock::today($e->timezone));
                } catch (\Throwable) {
                    continue;
                }
                $uid = $approvers->userIdOf($e);
                if ($uid && $s->isWorkingDay() && $s->start && $s->start->isFuture() && $s->start->diffInMinutes(now(), true) <= $minutes) {
                    $sent += $support->notify($uid, 'Shift starting soon', 'Your shift starts at '.$s->start->setTimezone($s->timezone)->format('H:i').'.', "shift:{$e->id}:{$s->date}", '/attendance', true, $e->timezone) ? 1 : 0;
                }
            }
        }

        // Pending-approval nudges after N days.
        $nudge = (int) RequestRules::setting('cutoffs.manager_approval_days', 2);
        foreach (Regularization::query()->where('status', 'pending')->where('created_at', '<=', now()->subDays($nudge))->get() as $r) {
            $e = Employee::query()->find($r->employee_id);
            foreach ($e ? $approvers->candidates($e, 'regularization', $r->attendance_date, (int) $r->current_step) : [] as $u) {
                $sent += $support->notify($u->id, 'Regularization waiting for you', "A regularization for {$r->attendance_date} has been waiting for {$nudge}+ days.", "nudge:{$r->uuid}:{$r->current_step}", '/time-office/approvals?kind=regularization', true) ? 1 : 0;
            }
        }

        return $sent;
    }
}
