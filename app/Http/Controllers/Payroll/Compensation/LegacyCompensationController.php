<?php

namespace App\Http\Controllers\Payroll\Compensation;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Compensation\CompensationService;
use App\Domain\Payroll\Models\EmployeeCompensation;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Pre-existing /api/v1/payroll/{employees,compensations} endpoints (paths and shapes preserved). New work lives in CompensationController. */
final class LegacyCompensationController
{
    public function employees(Request $r)
    {
        return app(DataScope::class)->constrain(Employee::query(), $r->user(), 'payroll.compensation.manage')->with('person')->orderBy('employee_number')->paginate(25)->through(fn ($e) => ['uuid' => $e->uuid, 'name' => $e->person->display_name, 'number' => $e->employee_number]);
    }

    public function compensations(Request $r, CompensationService $service)
    {
        $employees = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'payroll.compensation.view')->select('employees.id');
        Audit::record('compensation.viewed', 'payroll', 'compensation-list');

        return EmployeeCompensation::whereIn('employee_id', $employees)->with(['employee.person', 'group', 'lines.component'])->latest('id')->paginate(25)->through(fn ($c) => $service->present($c));
    }

    public function createCompensation(Request $r, CompensationService $service)
    {
        $data = $r->validate(['employee_uuid' => 'required|uuid', 'group_uuid' => 'required|uuid', 'structure_uuid' => 'nullable|uuid', 'effective_from' => 'required|date_format:Y-m-d', 'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'reason' => 'required|string|max:2000', 'idempotency_key' => 'required|uuid', 'lines' => 'required|array|min:1|max:50', 'lines.*.component_uuid' => 'required|uuid|distinct', 'lines.*.amount' => ['nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,2})?$/D']]);

        return response()->json(['uuid' => $service->create($r->user(), $data)->uuid], 201);
    }

    public function decide(Request $r, string $uuid, CompensationService $service)
    {
        $data = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);

        return ['uuid' => $service->decide($r->user(), $uuid, $data['decision'])->uuid];
    }
}
