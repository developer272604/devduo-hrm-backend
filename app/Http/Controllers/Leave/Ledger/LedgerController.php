<?php

namespace App\Http\Controllers\Leave\Ledger;

use App\Domain\Attendance\Operations\Ops;
use App\Domain\Leave\Accrual\BalanceReadService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/** Shared helpers for the leave ledger-operation controllers (no business logic: services do the work). */
abstract class LedgerController extends Controller
{
    protected function perPage(Request $r, int $default = 25): int
    {
        return Ops::perPage($r->query('per_page'), $default);
    }

    protected function page(LengthAwarePaginator $p, array $data): array
    {
        return ['data' => $data, 'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]];
    }

    protected function employeeByUuid(string $uuid): Employee
    {
        return Employee::byUuid($uuid);
    }

    protected function typeByUuid(string $uuid): LeaveType
    {
        return LeaveType::byUuid($uuid);
    }

    /** Employee the viewer may read (404 otherwise: existence is not revealed outside the data scope). */
    protected function viewableEmployee(Request $r, string $uuid, BalanceReadService $reads): Employee
    {
        $employee = $this->employeeByUuid($uuid);
        abort_unless($reads->canView($r->user(), $employee), 404);

        return $employee;
    }

    protected function viewableAccount(Request $r, string $uuid, BalanceReadService $reads): LeaveAccount
    {
        $account = LeaveAccount::byUuid($uuid);
        abort_unless($reads->canView($r->user(), $account->employee_id), 404);
        $type = LeaveType::query()->find($account->leave_type_id);
        abort_if($type?->is_sensitive && ! $reads->canSeeSensitive($r->user(), $account->employee_id), 404);

        return $account;
    }

    /**
     * Employee ids an operation may touch: the requested ids intersected with the user's data scope for the permission.
     * Tenant-wide holders with no filter get [] (meaning everyone); others always get an explicit list.
     *
     * @param  array<int,string>  $uuids
     * @return array<int,int>
     */
    protected function scopedEmployeeIds(User $user, string $permission, array $uuids): array
    {
        $ids = $uuids ? Employee::query()->whereIn('uuid', $uuids)->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
        if ($uuids && ! $ids) {
            return [0];
        }
        $tenantWide = app(\App\Domain\Access\DataScope::class)->hasTenantWide($user, $permission);
        if ($tenantWide) {
            return $ids;
        }
        $reach = Ops::employeeIds($user, $permission, false);

        return $ids ? array_values(array_intersect($ids, $reach)) ?: [0] : ($reach ?: [0]);
    }
}
