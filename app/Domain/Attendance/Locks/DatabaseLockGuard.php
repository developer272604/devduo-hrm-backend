<?php

namespace App\Domain\Attendance\Locks;

use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Models\AttendanceLock;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Edit protection for locked attendance. A lock covers an employee-date when the date is inside the lock's range and the employee
 * belongs to the lock's scope ON THAT DATE (organization attributes are read as of the date, not now).
 */
final class DatabaseLockGuard implements LockGuard
{
    public function isLocked(int $employeeId, string $date): bool
    {
        return $this->lockFor($employeeId, $date) !== null;
    }

    public function assertUnlocked(int $employeeId, string $date): void
    {
        $lock = $this->lockFor($employeeId, $date);
        if ($lock) {
            abort(423, 'Attendance for '.$date.' is locked ('.$lock['scope_label'].', "'.$lock['reason'].'"). To change it, request an unlock from Attendance > Locks; a different person with unlock permission must approve it.');
        }
    }

    public function lockFor(int $employeeId, string $date): ?array
    {
        $locks = AttendanceLock::query()->where('status', 'locked')->where('start_date', '<=', $date)->where('end_date', '>=', $date)->get()
            ->sortByDesc(fn ($l) => AttendanceLock::BREADTH[$l->scope_type] ?? 0);
        foreach ($locks as $lock) {
            if ($this->covers($lock, $employeeId, $date)) {
                return $this->describe($lock);
            }
        }

        return null;
    }

    /** True when the employee belongs to the lock's scope on the date (date range is NOT checked here). */
    public function covers(AttendanceLock $lock, int $employeeId, string $date): bool
    {
        $tenant = app(TenantContext::class)->id;
        switch ($lock->scope_type) {
            case 'tenant':
                return true;
            case 'employee':
                return (int) $lock->scope_id === $employeeId;
            case 'legal_entity':
            case 'location':
                $column = $lock->scope_type.'_id';

                return DB::table('employee_assignments')->where('tenant_id', $tenant)->where('employee_id', $employeeId)->where($column, $lock->scope_id)
                    ->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->exists();
            case 'employee_group':
                return DB::table('employee_group_members')->where('tenant_id', $tenant)->where('employee_id', $employeeId)->where('group_id', $lock->scope_id)
                    ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->exists();
            case 'payroll_group':
                return DB::table('employee_policy_references')->where('tenant_id', $tenant)->where('employee_id', $employeeId)->where('kind', 'payroll_group')->where('reference_id', $lock->scope_id)
                    ->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->exists();
        }

        return false;
    }

    public function describe(AttendanceLock $lock): array
    {
        return ['id' => $lock->id, 'uuid' => $lock->uuid, 'scope_type' => $lock->scope_type, 'scope_id' => $lock->scope_id, 'scope_label' => $this->label($lock->scope_type, $lock->scope_id), 'reason' => $lock->reason,
            'start_date' => $lock->start_date, 'end_date' => $lock->end_date, 'locked_at' => Utc::iso($lock->locked_at), 'locked_by' => $lock->locked_by];
    }

    public function label(string $type, string $id): string
    {
        $tenant = app(TenantContext::class)->id;
        if ($type === 'tenant') {
            return 'whole company';
        }
        if ($type === 'employee') {
            $name = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $tenant)->where('employees.id', $id)->value('people.display_name');

            return 'employee '.($name ?? $id);
        }
        $table = ['legal_entity' => 'legal_entities', 'location' => 'locations', 'employee_group' => 'employee_groups', 'payroll_group' => 'payroll_groups'][$type] ?? null;
        $name = $table ? DB::table($table)->where('tenant_id', $tenant)->where('id', $id)->value('name') : null;

        return str_replace('_', ' ', $type).' '.($name ?? $id);
    }
}
