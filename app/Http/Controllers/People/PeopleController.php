<?php

namespace App\Http\Controllers\People;

use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\People\Models\Employee;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Base for People controllers. Subject lookups are by public UUID and are tenant-scoped by the model.
 * A subject outside the viewer's data scope is reported as 404 (never 403) so existence is not disclosed.
 */
abstract class PeopleController extends Controller
{
    protected function employee(Request $r, string $uuid, string $permission = 'people.view', bool $allowSelf = true): Employee
    {
        $employee = Employee::query()->where('uuid', $uuid)->firstOrFail();
        $scope = app(DataScope::class);
        $user = $r->user();
        $reachable = $scope->canAccess($user, $employee, $permission) || ($allowSelf && $scope->isSelf($user, $employee));
        abort_unless($reachable, 404);

        return $employee;
    }

    protected function access(): FieldAccess
    {
        return app(FieldAccess::class);
    }

    /** Pagination size shared by list endpoints. */
    protected function perPage(Request $r, int $default = 25): int
    {
        return min(100, max(5, (int) $r->input('per_page', $default)));
    }
}
