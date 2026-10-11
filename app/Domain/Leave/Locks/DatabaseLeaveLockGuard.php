<?php

namespace App\Domain\Leave\Locks;

use App\Domain\Attendance\Locks\DatabaseLockGuard as AttendanceLockGuard;
use App\Domain\Attendance\Models\AttendanceLock;
use App\Domain\Leave\Contracts\LeaveLockGuard;
use App\Domain\Leave\Models\LeaveLock;
use App\Domain\Attendance\Services\Utc;

/**
 * Leave locks use the same scope vocabulary and the same "is the employee in the scope ON THAT DATE" logic as attendance locks;
 * that logic is reused (AttendanceLockGuard::covers), not copied. Workstream D adds the lock lifecycle (lock, unlock request,
 * maker-checker unlock, coordinated attendance cutoff) on top of leave_locks.
 */
final class DatabaseLeaveLockGuard implements LeaveLockGuard
{
    public function __construct(private AttendanceLockGuard $scopes) {}

    public function isLocked(int $employeeId, string $date): bool
    {
        return $this->lockFor($employeeId, $date) !== null;
    }

    public function assertUnlocked(int $employeeId, string $date): void
    {
        if ($lock = $this->lockFor($employeeId, $date)) {
            abort(423, 'Leave for '.$date.' is locked ('.$lock['scope_label'].', "'.$lock['reason'].'"). To change it, request an unlock from Leave > Locks; a different person with unlock permission must approve it.');
        }
    }

    public function lockFor(int $employeeId, string $date): ?array
    {
        $locks = LeaveLock::query()->where('status', 'locked')->where('start_date', '<=', $date)->where('end_date', '>=', $date)->get()
            ->sortByDesc(fn ($l) => AttendanceLock::BREADTH[$l->scope_type] ?? 0);
        foreach ($locks as $lock) {
            $probe = new AttendanceLock(['scope_type' => $lock->scope_type, 'scope_id' => $lock->scope_id]);
            if ($this->scopes->covers($probe, $employeeId, $date)) {
                return ['id' => $lock->id, 'uuid' => $lock->uuid, 'scope_type' => $lock->scope_type, 'scope_id' => $lock->scope_id,
                    'scope_label' => $this->scopes->label($lock->scope_type, $lock->scope_id), 'reason' => $lock->reason,
                    'start_date' => $lock->start_date, 'end_date' => $lock->end_date, 'locked_at' => Utc::iso($lock->locked_at), 'locked_by' => $lock->locked_by];
            }
        }

        return null;
    }
}
