<?php

namespace App\Http\Controllers\Attendance\Capture;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Capture\PunchPresenter;
use App\Domain\Attendance\Capture\PunchService;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Shared helpers for the capture controllers. Subjects outside the viewer's data scope are reported as 404. */
abstract class CaptureController extends Controller
{
    protected function service(): PunchService
    {
        return app(PunchService::class);
    }

    protected function presenter(): PunchPresenter
    {
        return app(PunchPresenter::class);
    }

    /** The authenticated user's current employment (never a client-supplied id). */
    protected function myEmployee(Request $r): Employee
    {
        $ids = app(DataScope::class)->ownEmployeeIds($r->user());
        $employee = $ids ? Employee::query()->whereIn('id', $ids)->whereIn('status', Employee::WORKING)->orderByDesc('joined_on')->orderByDesc('id')->first() : null;
        abort_unless($employee, 403, 'Your account is not linked to an active employee record, so attendance cannot be recorded. Please contact HR.');

        return $employee;
    }

    protected function scopedEmployee(Request $r, string $uuid, string $permission): Employee
    {
        $employee = Employee::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($r->user(), $employee, $permission), 404);

        return $employee;
    }

    protected function perPage(Request $r, int $default = 25): int
    {
        return min(100, max(5, (int) $r->input('per_page', $default)));
    }

    protected function page($paginator, array $items): array
    {
        return ['data' => $items, 'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]];
    }
}
