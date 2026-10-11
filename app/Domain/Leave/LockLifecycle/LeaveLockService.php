<?php

namespace App\Domain\Leave\LockLifecycle;

use App\Domain\Attendance\Locks\DatabaseLockGuard as AttendanceScopes;
use App\Domain\Attendance\Locks\LockService as AttendanceLocks;
use App\Domain\Attendance\Models\AttendanceLock;
use App\Domain\Attendance\Processing\ScopeExpander;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Models\LeaveLock;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Leave lock lifecycle on top of leave_locks (DatabaseLeaveLockGuard enforces them): preview with unresolved items, create
 * (optionally coordinated with an attendance cutoff), and the maker-checker unlock (requester states a reason; a DIFFERENT user
 * holding leave.unlock approves). Scope vocabulary and validation are the attendance ones (reused, not copied).
 */
final class LeaveLockService
{
    public function __construct(private AttendanceLocks $attendance, private AttendanceScopes $scopes, private ScopeExpander $expander, private DomainEvents $events, private NotificationService $notifications) {}

    /** What would be locked and what is unresolved. Never writes. */
    public function preview(array $data): array
    {
        $n = $this->attendance->normalize($data);
        $tenant = app(TenantContext::class)->id;
        $ids = [];
        $pairs = 0;
        foreach ($this->expander->expand($this->attendance->processingScope($n)) as $chunk) {
            foreach ($chunk as $employeeId => $dates) {
                $ids[] = $employeeId;
                $pairs += count($dates);
            }
        }
        $range = [$n['start_date'], $n['end_date']];
        $pendingRequests = 0;
        $pendingAdjustments = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $pendingRequests += DB::table('leave_requests')->where('tenant_id', $tenant)->whereIn('employee_id', $chunk)->where('status', 'pending')->where('start_date', '<=', $range[1])->where('end_date', '>=', $range[0])->count();
            $pendingAdjustments += DB::table('leave_adjustments')->where('tenant_id', $tenant)->whereIn('employee_id', $chunk)->where('status', 'pending')->count();
        }
        $total = $pendingRequests + $pendingAdjustments;
        $conflicts = $this->conflicts($n);
        $label = $this->scopes->label($n['scope_type'], $n['scope_id']);

        return ['scope' => $n + ['label' => $label], 'employees' => count($ids), 'employee_days' => $pairs,
            'unresolved' => ['pending_requests' => $pendingRequests, 'pending_adjustments' => $pendingAdjustments], 'unresolved_total' => $total, 'requires_acknowledgement' => $total > 0,
            'conflicts' => $conflicts, 'can_lock' => $conflicts === [] && count($ids) > 0,
            'message' => $total > 0 ? 'There are '.$total.' unresolved leave items for '.$label.'. Resolve them first, or lock anyway and acknowledge that they stay as they are.' : 'Everything for '.$label.' is resolved. It is safe to lock.'];
    }

    public function create(User $actor, array $data): LeaveLock
    {
        if (blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Say why this leave period is being locked (for example "September leave closed for payroll").']);
        }
        $preview = $this->preview($data);
        if ($preview['conflicts']) {
            throw ValidationException::withMessages(['scope_id' => $preview['conflicts'][0]['message']]);
        }
        if ($preview['employees'] === 0) {
            throw ValidationException::withMessages(['scope_id' => 'No employees were employed in that scope for those dates, so there is nothing to lock.']);
        }
        if ($preview['requires_acknowledgement'] && ! filter_var($data['acknowledge_unresolved'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['acknowledge_unresolved' => $preview['message'].' Send acknowledge_unresolved=true to lock anyway.']);
        }
        $coordinate = filter_var($data['coordinate_attendance'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($coordinate && ! $actor->allows('attendance.lock')) {
            throw ValidationException::withMessages(['coordinate_attendance' => 'Locking attendance as well needs the attendance lock permission.']);
        }
        $n = $preview['scope'];

        return DB::transaction(function () use ($actor, $n, $data, $preview, $coordinate) {
            $attendanceLock = $coordinate ? $this->attendance->create($actor, $n + ['reason' => $data['reason'], 'acknowledge_unresolved' => true]) : null;
            $lock = LeaveLock::create(['scope_type' => $n['scope_type'], 'scope_id' => $n['scope_id'], 'start_date' => $n['start_date'], 'end_date' => $n['end_date'], 'status' => 'locked', 'reason' => $data['reason'],
                'attendance_lock_id' => $attendanceLock?->id, 'locked_by' => $actor->id, 'locked_at' => Utc::str()]);
            Audit::record('locked', 'leave', 'leave_lock:'.$lock->uuid, ['scope' => $n, 'acknowledged_unresolved' => $preview['unresolved_total'], 'attendance_lock' => $attendanceLock?->uuid]);
            $this->changed($lock, $actor, 'locked', 'Leave locked for '.$n['label'].' from '.$n['start_date'].' to '.$n['end_date']);
            $this->notify(['leave.unlock', 'leave.lock', 'leave.manage'], $actor->id, 'Leave period locked', $n['label'].': '.$n['start_date'].' to '.$n['end_date'].' was locked by '.$actor->name.'.');

            return $lock;
        });
    }

    public function requestUnlock(LeaveLock $lock, User $actor, string $reason): LeaveLock
    {
        if ($lock->status !== 'locked') {
            throw ValidationException::withMessages(['lock' => 'This lock has already been released.']);
        }
        if ($lock->unlock_requested_by) {
            throw ValidationException::withMessages(['lock' => 'An unlock request is already waiting for approval by someone with unlock permission.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Explain why this period needs to be unlocked.']);
        }
        $lock->forceFill(['unlock_requested_by' => $actor->id, 'unlock_reason' => $reason])->save();
        Audit::record('unlock_requested', 'leave', 'leave_lock:'.$lock->uuid, ['reason' => $reason]);
        $this->notify(['leave.unlock'], $actor->id, 'Leave unlock requested', $actor->name.' asked to unlock '.$this->scopes->label($lock->scope_type, $lock->scope_id).' ('.$this->d($lock->start_date).' to '.$this->d($lock->end_date).'): '.$reason, '/leave/locks');

        return $lock;
    }

    public function rejectUnlock(LeaveLock $lock, User $actor, ?string $comment = null): LeaveLock
    {
        if ($lock->status !== 'locked' || ! $lock->unlock_requested_by) {
            throw ValidationException::withMessages(['lock' => 'There is no pending unlock request for this lock.']);
        }
        $requester = $lock->unlock_requested_by;
        $lock->forceFill(['unlock_requested_by' => null, 'unlock_reason' => null])->save();
        Audit::record('unlock_rejected', 'leave', 'leave_lock:'.$lock->uuid, ['requested_by' => $requester, 'comment' => $comment]);
        $this->send($requester, 'Leave unlock request declined', 'The request to unlock '.$this->scopes->label($lock->scope_type, $lock->scope_id).' was declined.'.($comment ? ' '.$comment : ''));

        return $lock;
    }

    /** The checker step: someone OTHER than the requester releases the lock. */
    public function approveUnlock(LeaveLock $lock, User $actor): LeaveLock
    {
        return DB::transaction(function () use ($lock, $actor) {
            $lock = LeaveLock::query()->whereKey($lock->id)->lockForUpdate()->firstOrFail();
            if ($lock->status !== 'locked') {
                throw ValidationException::withMessages(['lock' => 'This lock has already been released.']);
            }
            if (! $lock->unlock_requested_by) {
                throw ValidationException::withMessages(['lock' => 'Someone must request the unlock with a reason before it can be approved.']);
            }
            if ($lock->unlock_requested_by === $actor->id) {
                throw ValidationException::withMessages(['lock' => 'A different person must approve this unlock. Unlocking needs two people so a period cannot be reopened unnoticed.']);
            }
            $lock->forceFill(['status' => 'unlocked', 'unlocked_by' => $actor->id, 'unlocked_at' => Utc::str()])->save();
            Audit::record('unlocked', 'leave', 'leave_lock:'.$lock->uuid, ['requested_by' => $lock->unlock_requested_by, 'reason' => $lock->unlock_reason, 'attendance_lock_id' => $lock->attendance_lock_id]);
            $label = $this->scopes->label($lock->scope_type, $lock->scope_id);
            $this->changed($lock, $actor, 'unlocked', 'Leave unlocked for '.$label.' from '.$this->d($lock->start_date).' to '.$this->d($lock->end_date));
            $this->send($lock->unlock_requested_by, 'Leave unlocked', $label.' ('.$this->d($lock->start_date).' to '.$this->d($lock->end_date).') is open for corrections again.');

            return $lock;
        });
    }

    /** Active overlapping locks that conflict with (same scope) or make redundant the requested one. @return array<int,array<string,mixed>> */
    public function conflicts(array $n): array
    {
        $out = [];
        foreach (LeaveLock::query()->where('status', 'locked')->where('start_date', '<=', $n['end_date'])->where('end_date', '>=', $n['start_date'])->get() as $existing) {
            if ($existing->scope_type === $n['scope_type'] && (string) $existing->scope_id === (string) $n['scope_id']) {
                $out[] = ['lock_uuid' => $existing->uuid, 'type' => 'conflict', 'message' => 'This overlaps an existing lock on '.$this->scopes->label($existing->scope_type, $existing->scope_id).' ('.$this->d($existing->start_date).' to '.$this->d($existing->end_date).'). Unlock it first or choose different dates.'];
            } elseif ((AttendanceLock::BREADTH[$existing->scope_type] ?? 0) > (AttendanceLock::BREADTH[$n['scope_type']] ?? 0) && $this->covered($existing, $n)) {
                $out[] = ['lock_uuid' => $existing->uuid, 'type' => 'redundant', 'message' => 'These dates are already locked by a broader lock on '.$this->scopes->label($existing->scope_type, $existing->scope_id).', so a separate lock is not needed.'];
            }
        }

        return $out;
    }

    private function covered(LeaveLock $broader, array $n): bool
    {
        $any = false;
        $probe = new AttendanceLock(['scope_type' => $broader->scope_type, 'scope_id' => $broader->scope_id]);
        foreach ($this->expander->expand($this->attendance->processingScope($n)) as $chunk) {
            foreach ($chunk as $employeeId => $dates) {
                foreach ($dates as $date) {
                    $any = true;
                    if ($date < $this->d($broader->start_date) || $date > $this->d($broader->end_date) || ! $this->scopes->covers($probe, $employeeId, $date)) {
                        return false;
                    }
                }
            }
        }

        return $any;
    }

    public function present(LeaveLock $l): array
    {
        $attendance = $l->attendance_lock_id ? DB::table('attendance_locks')->where('id', $l->attendance_lock_id)->value('uuid') : null;

        return ['uuid' => $l->uuid, 'scope_type' => $l->scope_type, 'scope_id' => $l->scope_id, 'scope_label' => $this->scopes->label($l->scope_type, $l->scope_id), 'start_date' => $this->d($l->start_date), 'end_date' => $this->d($l->end_date),
            'status' => $l->status, 'reason' => $l->reason, 'locked_at' => Utc::iso($l->locked_at), 'unlock_requested' => (bool) $l->unlock_requested_by, 'unlock_reason' => $l->unlock_reason,
            'unlocked_at' => Utc::iso($l->unlocked_at), 'attendance_lock_uuid' => $attendance];
    }

    private function d(mixed $v): string
    {
        return substr((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v), 0, 10);
    }

    private function changed(LeaveLock $lock, User $actor, string $action, string $summary): void
    {
        $this->events->record(LeaveEvents::LOCK_CHANGED, ['subject' => ['leave_lock', $lock->id], 'actor_id' => $actor->id, 'effective_date' => $this->d($lock->start_date), 'summary' => $summary,
            'payload' => ['lock_uuid' => $lock->uuid, 'action' => $action, 'scope_type' => $lock->scope_type, 'scope_id' => $lock->scope_id, 'start_date' => $this->d($lock->start_date), 'end_date' => $this->d($lock->end_date)]]);
    }

    /** @param array<int,string> $permissions */
    private function notify(array $permissions, int $actorId, string $title, string $body, ?string $action = '/leave/locks'): void
    {
        $ids = DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('users.tenant_id', app(TenantContext::class)->id)->where('users.active', true)->whereIn('permissions.key', $permissions)->distinct()->pluck('users.id')->all();
        foreach (array_diff($ids, [$actorId]) as $id) {
            $this->send($id, $title, $body, $action);
        }
    }

    private function send(int $userId, string $title, string $body, ?string $action = '/leave/locks'): void
    {
        try {
            $this->notifications->send($userId, $title, $body, 'leave', $action);
        } catch (\Throwable $e) {
            Log::warning('leave.notification_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
