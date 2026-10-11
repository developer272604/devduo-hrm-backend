<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Tenancy\TenantClock;

/**
 * Side-effect helpers shared by every scheduling mutation: attendance-lock protection and recalculation requests.
 * Both collaborators are optional at runtime (other workstreams bind them): unbound LockGuard = nothing is locked,
 * unbound recalculator = recalculation is skipped.
 */
final class SchedulingEffects
{
    public static function today(): string
    {
        return TenantClock::today();
    }

    /** Refuses (HTTP 423 from the guard) when the employee-date is in a locked attendance period. Only dates up to today can be locked. */
    public static function assertUnlocked(int $employeeId, string $date): void
    {
        if ($date <= self::today() && app()->bound(LockGuard::class)) {
            app(LockGuard::class)->assertUnlocked($employeeId, $date);
        }
    }

    /**
     * Lock check for a whole range and many employees without one query per day: probes the first day, the last past day and
     * the first/last day of every month in between (locks are period based). Dates in the future are never locked.
     *
     * @param  array<int,int>  $employeeIds
     */
    public static function assertRangeUnlocked(array $employeeIds, string $from, string $to): void
    {
        $to = min($to, self::today());
        if ($from > $to || ! app()->bound(LockGuard::class)) {
            return;
        }
        $probe = [$from, $to];
        for ($d = \Carbon\CarbonImmutable::parse($from)->startOfMonth()->addMonth(); $d->toDateString() <= $to; $d = $d->addMonth()) {
            $probe[] = $d->toDateString();
            $probe[] = min($d->endOfMonth()->toDateString(), $to);
        }
        foreach (array_slice(array_unique($employeeIds), 0, 2000) as $employeeId) {
            foreach (array_unique($probe) as $date) {
                app(LockGuard::class)->assertUnlocked((int) $employeeId, $date);
            }
        }
    }

    public static function isLocked(int $employeeId, string $date): bool
    {
        return $date <= self::today() && app()->bound(LockGuard::class) && app(LockGuard::class)->isLocked($employeeId, $date);
    }

    /**
     * Requests recalculation for dates up to today (future dates have no attendance yet).
     *
     * @param  array<int,int>  $employeeIds
     * @return string|null processing run uuid when requested
     */
    public static function recalculate(array $employeeIds, string $from, string $to, ?int $userId = null, ?string $dedupeKey = null): ?string
    {
        $today = self::today();
        $to = min($to, $today);
        $employeeIds = array_values(array_unique($employeeIds));
        if (! $employeeIds || $from > $to || ! app()->bound(AttendanceRecalculator::class)) {
            return null;
        }

        return app(AttendanceRecalculator::class)->recalculate(['employee_ids' => $employeeIds, 'from' => $from, 'to' => $to], 'recalculation', $userId, $dedupeKey);
    }
}
