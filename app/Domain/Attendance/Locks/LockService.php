<?php

namespace App\Domain\Attendance\Locks;

use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceException;
use App\Domain\Attendance\Models\AttendanceLock;
use App\Domain\Attendance\Processing\ScopeExpander;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Attendance locks: create (with an unresolved-items preview and explicit acknowledgement), hierarchy/conflict rules, and the
 * maker-checker unlock workflow (requester records a reason; a DIFFERENT user with attendance.unlock approves).
 *
 * Hierarchy (broad to narrow): tenant > legal_entity > payroll_group > location > employee_group > employee.
 *   - Two active locks of the same scope and overlapping dates conflict.
 *   - A narrower lock whose employees are all already covered by a broader active lock is redundant and rejected.
 *   - A broader lock may be created over an existing narrower one; days keep one lock reference and are re-pointed on unlock.
 */
final class LockService
{
    private const SCOPE_KEYS = ['tenant' => 'tenant', 'legal_entity' => 'legal_entity_id', 'payroll_group' => 'payroll_group_id', 'location' => 'location_id', 'employee_group' => 'employee_group_id', 'employee' => 'employee_ids'];

    public function __construct(private readonly ScopeExpander $scopes, private readonly DatabaseLockGuard $guard, private readonly DomainEvents $events, private readonly NotificationService $notifications) {}

    /** Validates and normalizes lock input (scope existence, dates). @return array{scope_type:string,scope_id:string,start_date:string,end_date:string} */
    public function normalize(array $data, bool $requireFinishedDates = true): array
    {
        $type = $data['scope_type'] ?? null;
        if (! in_array($type, AttendanceLock::SCOPES, true)) {
            throw ValidationException::withMessages(['scope_type' => 'Choose what to lock: '.implode(', ', AttendanceLock::SCOPES).'.']);
        }
        $tenant = app(TenantContext::class)->id;
        $id = (string) ($data['scope_id'] ?? '*');
        if ($type === 'tenant') {
            $id = '*';
        } elseif ($type === 'employee') {
            $id = ctype_digit($id) ? $id : (string) DB::table('employees')->where('tenant_id', $tenant)->where('uuid', $id)->value('id');
            $exists = $id !== '' && DB::table('employees')->where('tenant_id', $tenant)->where('id', $id)->exists();
        } else {
            $table = ['legal_entity' => 'legal_entities', 'location' => 'locations', 'employee_group' => 'employee_groups', 'payroll_group' => 'payroll_groups'][$type];
            $exists = DB::table($table)->where('tenant_id', $tenant)->where('id', $id)->exists();
        }
        if ($type !== 'tenant' && ! ($exists ?? false)) {
            throw ValidationException::withMessages(['scope_id' => 'We could not find that '.str_replace('_', ' ', $type).'.']);
        }
        $from = $data['start_date'] ?? null;
        $to = $data['end_date'] ?? null;
        foreach (['start_date' => $from, 'end_date' => $to] as $field => $value) {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                throw ValidationException::withMessages([$field => 'Use the format YYYY-MM-DD for dates.']);
            }
        }
        if ($to < $from) {
            throw ValidationException::withMessages(['end_date' => 'The end date cannot be before the start date.']);
        }
        if ($requireFinishedDates && $to > TenantClock::today()) {
            throw ValidationException::withMessages(['end_date' => 'You can only lock dates that have already passed. The last lockable date is '.TenantClock::today().'.']);
        }

        return ['scope_type' => $type, 'scope_id' => $id, 'start_date' => $from, 'end_date' => $to];
    }

    /** What would be locked and what is still unresolved. Never writes. */
    public function preview(array $data, bool $requireFinishedDates = true): array
    {
        $n = $this->normalize($data, $requireFinishedDates);
        $scope = $this->processingScope($n);
        $tenant = app(TenantContext::class)->id;
        $today = TenantClock::today();
        $employees = 0;
        $pairs = 0;
        $existing = 0;
        $unprocessed = 0;
        $ids = [];
        foreach ($this->scopes->expand($scope) as $chunk) {
            $chunkIds = array_keys($chunk);
            $ids = array_merge($ids, $chunkIds);
            $employees += count($chunkIds);
            $have = DB::table('attendance_days')->where('tenant_id', $tenant)->whereIn('employee_id', $chunkIds)->whereBetween('attendance_date', [$n['start_date'], $n['end_date']])->get(['employee_id', 'attendance_date'])
                ->map(fn ($r) => $r->employee_id.'|'.substr($r->attendance_date, 0, 10))->flip();
            foreach ($chunk as $employeeId => $dates) {
                foreach ($dates as $date) {
                    $pairs++;
                    if ($have->has($employeeId.'|'.$date)) {
                        $existing++;
                    } elseif ($date <= $today) {
                        $unprocessed++;
                    }
                }
            }
        }
        $range = [$n['start_date'], $n['end_date']];
        $exceptions = ['action_required' => 0, 'missing_policy' => 0, 'missing_shift' => 0];
        $byType = [];
        $requests = ['regularizations' => 0, 'overtime' => 0, 'on_duty' => 0, 'adjustments' => 0];
        foreach (array_chunk($ids, 500) as $chunkIds) {
            $open = AttendanceException::query()->whereIn('employee_id', $chunkIds)->whereBetween('attendance_date', $range)->whereIn('status', AttendanceException::OPEN_STATES)
                ->whereIn('severity', ['action_required', 'critical'])->get(['type']);
            foreach ($open as $e) {
                $exceptions['action_required']++;
                $byType[$e->type] = ($byType[$e->type] ?? 0) + 1;
                $exceptions['missing_policy'] += $e->type === 'no_policy' ? 1 : 0;
                $exceptions['missing_shift'] += $e->type === 'no_shift' ? 1 : 0;
            }
            $requests['regularizations'] += DB::table('attendance_regularizations')->where('tenant_id', $tenant)->whereIn('employee_id', $chunkIds)->whereBetween('attendance_date', $range)->where('status', 'pending')->count();
            $requests['overtime'] += DB::table('overtime_requests')->where('tenant_id', $tenant)->whereIn('employee_id', $chunkIds)->whereBetween('work_date', $range)->where('status', 'pending')->count();
            $requests['on_duty'] += DB::table('on_duty_requests')->where('tenant_id', $tenant)->whereIn('employee_id', $chunkIds)->where('start_date', '<=', $range[1])->where('end_date', '>=', $range[0])->where('status', 'pending')->count();
            $requests['adjustments'] += DB::table('attendance_adjustments')->where('tenant_id', $tenant)->whereIn('employee_id', $chunkIds)->whereBetween('attendance_date', $range)->where('status', 'pending_approval')->count();
        }
        $unresolved = ['action_required_exceptions' => $exceptions['action_required'], 'exceptions_by_type' => $byType, 'missing_policy' => $exceptions['missing_policy'], 'missing_shift' => $exceptions['missing_shift'],
            'pending_regularizations' => $requests['regularizations'], 'pending_overtime' => $requests['overtime'], 'pending_on_duty' => $requests['on_duty'], 'pending_adjustments' => $requests['adjustments'], 'unprocessed_days' => $unprocessed];
        $total = $unresolved['action_required_exceptions'] + $unresolved['pending_regularizations'] + $unresolved['pending_overtime'] + $unresolved['pending_on_duty'] + $unresolved['pending_adjustments'] + $unprocessed;
        $conflicts = $this->conflicts($n, $employees > 0);
        $label = $this->guard->label($n['scope_type'], $n['scope_id']);

        return ['scope' => $n + ['label' => $label], 'employees' => $employees, 'employee_days' => $pairs, 'existing_days' => $existing, 'unresolved' => $unresolved, 'unresolved_total' => $total, 'requires_acknowledgement' => $total > 0,
            'conflicts' => $conflicts, 'can_lock' => $conflicts === [] && $employees > 0,
            'message' => $total > 0 ? 'There are '.$total.' unresolved items in '.$label.' for these dates. Resolve them first, or lock anyway and acknowledge that they will be left as they are.' : 'Everything for '.$label.' is resolved. It is safe to lock.'];
    }

    public function create(User $actor, array $data): AttendanceLock
    {
        if (blank($data['reason'] ?? null)) {
            throw ValidationException::withMessages(['reason' => 'Say why this period is being locked (for example "September attendance finalized for payroll").']);
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
        $n = $preview['scope'];

        return DB::transaction(function () use ($actor, $n, $data, $preview) {
            $lock = AttendanceLock::create(['scope_type' => $n['scope_type'], 'scope_id' => $n['scope_id'], 'start_date' => $n['start_date'], 'end_date' => $n['end_date'], 'status' => 'locked',
                'reason' => $data['reason'], 'locked_by' => $actor->id, 'locked_at' => Utc::str(), 'created_at' => Utc::str(), 'updated_at' => Utc::str()]);
            $locked = 0;
            foreach ($this->scopes->expand($this->processingScope($n)) as $chunk) {
                foreach ($chunk as $employeeId => $dates) {
                    $locked += AttendanceDay::query()->where('employee_id', $employeeId)->whereIn('attendance_date', $dates)->where('finalization', '!=', 'locked')
                        ->update(['finalization' => 'locked', 'lock_id' => $lock->id]);
                }
            }
            Audit::record('locked', 'attendance', 'attendance_lock:'.$lock->uuid, ['scope' => $n, 'days_locked' => $locked, 'acknowledged_unresolved' => $preview['unresolved_total']]);
            $this->events->record('AttendanceLocked', ['subject' => ['attendance_lock', $lock->id], 'actor_id' => $actor->id, 'effective_date' => $n['start_date'], 'summary' => 'Attendance locked for '.$n['label'].' from '.$n['start_date'].' to '.$n['end_date'],
                'payload' => ['lock_uuid' => $lock->uuid, 'scope_type' => $n['scope_type'], 'scope_id' => $n['scope_id'], 'start_date' => $n['start_date'], 'end_date' => $n['end_date'], 'days_locked' => $locked]]);
            $this->notify(['attendance.unlock', 'attendance.lock', 'attendance.manage'], $actor->id, 'Attendance period locked', $n['label'].': '.$n['start_date'].' to '.$n['end_date'].' was locked by '.$actor->name.'.');
            $lock->days_locked = $locked;

            return $lock;
        });
    }

    public function requestUnlock(AttendanceLock $lock, User $actor, string $reason): AttendanceLock
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
        $lock->forceFill(['unlock_requested_by' => $actor->id, 'unlock_reason' => $reason, 'updated_at' => Utc::str()])->save();
        Audit::record('unlock_requested', 'attendance', 'attendance_lock:'.$lock->uuid, ['reason' => $reason]);
        $this->notify(['attendance.unlock'], $actor->id, 'Attendance unlock requested', $actor->name.' asked to unlock '.$this->guard->label($lock->scope_type, $lock->scope_id).' ('.$lock->start_date.' to '.$lock->end_date.'): '.$reason, '/attendance/locks');

        return $lock;
    }

    public function rejectUnlock(AttendanceLock $lock, User $actor, ?string $comment = null): AttendanceLock
    {
        if ($lock->status !== 'locked' || ! $lock->unlock_requested_by) {
            throw ValidationException::withMessages(['lock' => 'There is no pending unlock request for this lock.']);
        }
        $requester = $lock->unlock_requested_by;
        $lock->forceFill(['unlock_requested_by' => null, 'unlock_reason' => null, 'updated_at' => Utc::str()])->save();
        Audit::record('unlock_rejected', 'attendance', 'attendance_lock:'.$lock->uuid, ['requested_by' => $requester, 'comment' => $comment]);
        $this->safeSend($requester, 'Attendance unlock request declined', 'The request to unlock '.$this->guard->label($lock->scope_type, $lock->scope_id).' was declined.'.($comment ? ' '.$comment : ''));

        return $lock;
    }

    /** The checker step: someone OTHER than the requester releases the lock. */
    public function approveUnlock(AttendanceLock $lock, User $actor): AttendanceLock
    {
        return DB::transaction(function () use ($lock, $actor) {
            $lock = AttendanceLock::query()->whereKey($lock->id)->lockForUpdate()->firstOrFail();
            if ($lock->status !== 'locked') {
                throw ValidationException::withMessages(['lock' => 'This lock has already been released.']);
            }
            if (! $lock->unlock_requested_by) {
                throw ValidationException::withMessages(['lock' => 'Someone must request the unlock with a reason before it can be approved.']);
            }
            if ($lock->unlock_requested_by === $actor->id) {
                throw ValidationException::withMessages(['lock' => 'A different person must approve this unlock. Unlocking needs two people so a period cannot be reopened unnoticed.']);
            }
            $lock->forceFill(['status' => 'unlocked', 'unlocked_by' => $actor->id, 'unlocked_at' => Utc::str(), 'updated_at' => Utc::str()])->save();
            $reopened = $this->reconcileDays($lock);
            Audit::record('unlocked', 'attendance', 'attendance_lock:'.$lock->uuid, ['requested_by' => $lock->unlock_requested_by, 'reason' => $lock->unlock_reason, 'days_reopened' => $reopened]);
            $this->events->record('AttendanceUnlocked', ['subject' => ['attendance_lock', $lock->id], 'actor_id' => $actor->id, 'effective_date' => $lock->start_date,
                'summary' => 'Attendance unlocked for '.$this->guard->label($lock->scope_type, $lock->scope_id).' from '.$lock->start_date.' to '.$lock->end_date,
                'payload' => ['lock_uuid' => $lock->uuid, 'scope_type' => $lock->scope_type, 'scope_id' => $lock->scope_id, 'start_date' => $lock->start_date, 'end_date' => $lock->end_date, 'requested_by' => $lock->unlock_requested_by, 'days_reopened' => $reopened]]);
            $label = $this->guard->label($lock->scope_type, $lock->scope_id);
            $this->safeSend($lock->unlock_requested_by, 'Attendance unlocked', $label.' ('.$lock->start_date.' to '.$lock->end_date.') is open for corrections again.');
            $this->notify(['attendance.unlock', 'attendance.manage'], $actor->id, 'Attendance period unlocked', $label.': '.$lock->start_date.' to '.$lock->end_date.' was unlocked by '.$actor->name.'.', null, [$lock->unlock_requested_by]);
            $lock->days_reopened = $reopened;

            return $lock;
        });
    }

    /** Moves the lock's days back to open, unless another active lock still covers them (then they point at that lock). @return int days reopened */
    public function reconcileDays(AttendanceLock $released): int
    {
        $others = AttendanceLock::query()->where('status', 'locked')->where('id', '!=', $released->id)->where('start_date', '<=', $released->end_date)->where('end_date', '>=', $released->start_date)->get();
        $reopened = 0;
        foreach (AttendanceDay::query()->where('lock_id', $released->id)->orderBy('id')->cursor() as $day) {
            $other = $others->first(fn ($l) => $l->start_date <= $day->attendance_date && $l->end_date >= $day->attendance_date && $this->guard->covers($l, $day->employee_id, $day->attendance_date));
            if ($other) {
                $day->lock_id = $other->id;
            } else {
                $day->finalization = 'open';
                $day->lock_id = null;
                $reopened++;
            }
            $day->save();
        }

        return $reopened;
    }

    /** Marks confirmed days without open exceptions as approved (the step before a lock). @return array{approved:int,skipped:int} */
    public function approveDays(array $data): array
    {
        $n = $this->normalize($data, false);
        $approved = 0;
        $skipped = 0;
        foreach ($this->scopes->expand($this->processingScope($n)) as $chunk) {
            foreach ($chunk as $employeeId => $dates) {
                $base = AttendanceDay::query()->where('employee_id', $employeeId)->whereIn('attendance_date', $dates)->where('finalization', 'open');
                $approved += (clone $base)->where('confidence', 'confirmed')->where('open_exceptions', 0)->update(['finalization' => 'approved']);
                $skipped += (clone $base)->count();
            }
        }
        Audit::record('approved_days', 'attendance', 'attendance_days', ['scope' => $n, 'approved' => $approved, 'skipped' => $skipped]);

        return ['approved' => $approved, 'skipped' => $skipped];
    }

    /** Active overlapping locks that conflict with (or make redundant) the requested one. @return array<int,array<string,mixed>> */
    public function conflicts(array $n, bool $hasEmployees = true): array
    {
        $out = [];
        $overlapping = AttendanceLock::query()->where('status', 'locked')->where('start_date', '<=', $n['end_date'])->where('end_date', '>=', $n['start_date'])->get();
        foreach ($overlapping as $existing) {
            $label = $this->guard->label($existing->scope_type, $existing->scope_id);
            if ($existing->scope_type === $n['scope_type'] && $existing->scope_id === $n['scope_id']) {
                $out[] = ['lock_uuid' => $existing->uuid, 'type' => 'conflict', 'message' => 'This overlaps an existing lock on '.$label.' ('.$existing->start_date.' to '.$existing->end_date.'). Unlock it first or choose different dates.'];
            } elseif ((AttendanceLock::BREADTH[$existing->scope_type] ?? 0) > (AttendanceLock::BREADTH[$n['scope_type']] ?? 0) && $hasEmployees && $this->redundant($existing, $n)) {
                $out[] = ['lock_uuid' => $existing->uuid, 'type' => 'redundant', 'message' => 'These dates are already locked by a broader lock on '.$label.' ('.$existing->start_date.' to '.$existing->end_date.'), so a separate lock is not needed.'];
            }
        }

        return $out;
    }

    /** Every employee-date of the requested scope is already covered by the broader lock. */
    private function redundant(AttendanceLock $broader, array $n): bool
    {
        $any = false;
        foreach ($this->scopes->expand($this->processingScope($n)) as $chunk) {
            foreach ($chunk as $employeeId => $dates) {
                foreach ($dates as $date) {
                    $any = true;
                    if ($date < $broader->start_date || $date > $broader->end_date || ! $this->guard->covers($broader, $employeeId, $date)) {
                        return false;
                    }
                }
            }
        }

        return $any;
    }

    /** @return array<string,mixed> processing scope equivalent of a lock scope */
    public function processingScope(array $n): array
    {
        $key = self::SCOPE_KEYS[$n['scope_type']];
        $scope = ['from' => $n['start_date'], 'to' => $n['end_date']];
        $scope[$key] = $key === 'tenant' ? true : ($key === 'employee_ids' ? [(int) $n['scope_id']] : (int) $n['scope_id']);

        return $scope;
    }

    /** @param array<int,string> $permissions @param array<int,int> $alsoExclude */
    private function notify(array $permissions, int $actorId, string $title, string $body, ?string $action = '/attendance/locks', array $alsoExclude = []): void
    {
        $tenant = app(TenantContext::class)->id;
        $ids = DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')->where('users.tenant_id', $tenant)->where('users.active', true)->whereIn('permissions.key', $permissions)
            ->distinct()->pluck('users.id')->all();
        foreach (array_diff($ids, [$actorId], $alsoExclude) as $id) {
            $this->safeSend($id, $title, $body, $action);
        }
    }

    private function safeSend(int $userId, string $title, string $body, ?string $action = '/attendance/locks'): void
    {
        try {
            $this->notifications->send($userId, $title, $body, 'attendance', $action);
        } catch (\Throwable $e) {
            Log::warning('attendance.notification_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
