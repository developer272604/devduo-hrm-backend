<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Bulk\BulkAttendanceAction;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Locks\LockService;
use App\Domain\Attendance\Models\AttendanceLock;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Bulk "recalculate", "lock" and "unlock" actions (the generic runner lives elsewhere). */
final class BulkActions implements BulkAttendanceAction
{
    public function __construct(private readonly string $key) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return ['recalculate' => 'Recalculate attendance', 'lock' => 'Lock attendance', 'unlock' => 'Unlock attendance'][$this->key];
    }

    public function permission(): string
    {
        return ['recalculate' => 'attendance.process', 'lock' => 'attendance.lock', 'unlock' => 'attendance.unlock'][$this->key];
    }

    public function targetKind(): string
    {
        return $this->key === 'recalculate' ? 'employee_date' : 'lock';
    }

    public function validate(array $params): array
    {
        if (in_array($this->key, ['lock', 'unlock'], true) && blank($params['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Say why this is needed.']);
        }

        return $params;
    }

    public function preview(User $actor, array $target, array $params): array
    {
        if ($this->key === 'recalculate') {
            return app(LockGuard::class)->isLocked((int) $target['employee_id'], $target['date'])
                ? ['status' => 'skipped', 'message' => 'This day is locked and will be skipped.'] : ['status' => 'ok', 'message' => 'Attendance will be recalculated.'];
        }
        if ($this->key === 'lock') {
            $p = app(LockService::class)->preview($target + $params);

            return ['status' => $p['conflicts'] ? 'error' : 'ok', 'message' => $p['conflicts'][0]['message'] ?? $p['message'], 'impact' => ['employee_days' => $p['employee_days'], 'unresolved' => $p['unresolved_total']]];
        }
        $lock = AttendanceLock::query()->where('uuid', $target['lock_uuid'] ?? '')->first();

        return $lock && $lock->status === 'locked' ? ['status' => 'ok', 'message' => 'An unlock request will be recorded for approval.'] : ['status' => 'error', 'message' => 'That lock is not active.'];
    }

    public function apply(User $actor, array $target, array $params): array
    {
        if ($this->key === 'recalculate') {
            $o = app(DayProcessor::class)->process((int) $target['employee_id'], $target['date'], 'manual', $actor->id);

            return ['status' => $o['outcome'] === 'skipped_locked' ? 'skipped' : 'ok', 'message' => $o['outcome']];
        }
        if ($this->key === 'lock') {
            $lock = app(LockService::class)->create($actor, $target + $params);

            return ['status' => 'ok', 'message' => 'Locked '.($lock->days_locked ?? 0).' days.'];
        }
        app(LockService::class)->requestUnlock(AttendanceLock::query()->where('uuid', $target['lock_uuid'])->firstOrFail(), $actor, (string) $params['reason']);

        return ['status' => 'ok', 'message' => 'Unlock requested; a different approver must confirm it.'];
    }
}
