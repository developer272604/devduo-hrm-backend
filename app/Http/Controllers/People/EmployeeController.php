<?php

namespace App\Http\Controllers\People;

use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\Access\FieldRegistry;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\AssignmentService;
use App\Domain\People\Services\EmployeeDirectory;
use App\Domain\People\Services\EmployeePresenter;
use App\Domain\People\Services\EmployeeRules;
use App\Domain\People\Services\EmployeeService;
use App\Domain\People\Services\NumberingService;
use App\Domain\People\Services\ReportingService;
use App\Domain\People\Services\StatusService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends PeopleController
{
    public function index(Request $r, EmployeeDirectory $directory)
    {
        $r->validate(['sort' => 'nullable|in:'.implode(',', array_keys(EmployeeDirectory::SORTS)), 'dir' => 'nullable|in:asc,desc', 'per_page' => 'nullable|integer|between:5,100']);

        return $directory->search($r->user(), $r->all(), 'manage');
    }

    public function coworkers(Request $r, EmployeeDirectory $directory)
    {
        $r->validate(['sort' => 'nullable|in:name,department,designation,location', 'dir' => 'nullable|in:asc,desc']);

        return $directory->search($r->user(), array_intersect_key($r->all(), array_flip(['q', 'department_id', 'location_id', 'sort', 'dir', 'per_page', 'page'])), 'directory');
    }

    public function show(Request $r, string $uuid, EmployeePresenter $presenter)
    {
        return $presenter->profile($r->user(), $this->employee($r, $uuid));
    }

    /** The signed-in user's own employment (null when no employee record is linked yet). */
    public function mine(Request $r, EmployeeService $service)
    {
        $person = \App\Domain\People\Models\Person::query()->where('user_id', $r->user()->id)->first();
        $employee = $person ? $service->currentEmployment($person) : null;

        return ['employee_uuid' => $employee?->uuid, 'status' => $employee?->status, 'display_name' => $person?->display_name];
    }

    public function store(Request $r, EmployeeService $service)
    {
        $data = $r->validate(
            ['person' => 'required|array', 'employment' => 'required|array', 'assignment' => 'sometimes|array', 'manager_id' => ['nullable', 'integer'], 'user_id' => ['nullable', 'integer'], 'confirm_duplicates' => 'sometimes|boolean']
            + EmployeeRules::prefixed('person', EmployeeRules::person()) + EmployeeRules::prefixed('employment', EmployeeRules::employment()) + EmployeeRules::prefixed('assignment', EmployeeRules::assignment())
        );
        $employee = $service->create($data);

        return response()->json(['uuid' => $employee->uuid, 'employee_number' => $employee->employee_number, 'status' => $employee->status], 201);
    }

    public function quickAdd(Request $r, EmployeeService $service)
    {
        $rules = [
            'person' => 'required|array', 'employment' => 'required|array', 'assignment' => 'required|array', 'manager_id' => ['nullable', 'integer'], 'confirm_duplicates' => 'sometimes|boolean',
            'person.legal_first_name' => 'required|string|max:120', 'person.legal_last_name' => 'nullable|string|max:120', 'employment.joined_on' => 'required|date_format:Y-m-d',
            'employment.worker_type' => ['sometimes', Rule::in(EmployeeRules::WORKER_TYPES)], 'employment.work_email' => 'nullable|email:rfc|max:255', 'employment.country' => 'nullable|string|size:2',
        ];
        $required = ['legal_entity_id', 'location_id', 'department_id', 'designation_id', 'employee_type_id'];
        foreach (EmployeeRules::assignment() as $field => $rule) {
            $rules['assignment.'.$field] = in_array($field, $required, true) ? array_merge(['required'], array_diff($rule, ['nullable'])) : $rule;
        }
        $data = $r->validate($rules);
        $data['employment']['worker_type'] ??= 'employee';
        $employee = $service->quickAdd($data);

        return response()->json(['uuid' => $employee->uuid, 'employee_number' => $employee->employee_number, 'status' => $employee->status], 201);
    }

    public function updateEmployment(Request $r, string $uuid, EmployeeService $service)
    {
        $employee = $this->employee($r, $uuid, 'people.manage', false);
        $rules = collect(EmployeeRules::employment(false))->only(EmployeeService::EMPLOYMENT_EDITABLE)->all() + ['employment_start_date' => ['nullable', 'date_format:Y-m-d']];
        $data = $r->validate($rules);
        // Employment changes must not move people into impossible dates.
        abort_if(isset($data['probation_end'], $data['probation_start']) && $data['probation_end'] < $data['probation_start'], 422, 'Probation cannot end before it starts.');
        $service->updateEmployment($employee, $data);

        return app(EmployeePresenter::class)->profile($r->user(), $employee->refresh());
    }

    /**
     * Updates personal fields the viewer may edit directly. Fields that require approval are rejected with
     * code "change_request_required"; use POST employees/{uuid}/change-requests for those.
     */
    public function updatePersonal(Request $r, string $uuid, EmployeeService $service)
    {
        $employee = $this->employee($r, $uuid);
        $rules = collect(EmployeeRules::person(false))->except(['country', 'timezone'])->all();
        $data = $r->validate($rules);
        abort_if($data === [], 422, 'No changes were submitted.');
        $relation = $this->access()->relation($r->user(), $employee);
        $needsRequest = [];
        foreach (array_keys($data) as $field) {
            $mode = $this->access()->edit($r->user(), 'person.'.$field, $employee, $relation);
            abort_if($mode === 'none', 403, 'You cannot edit '.str_replace('_', ' ', $field).'.');
            if ($mode === 'request') {
                $needsRequest[] = $field;
            }
        }
        if ($needsRequest) {
            return response()->json(['message' => 'These changes need approval before they apply.', 'code' => 'change_request_required', 'fields' => $needsRequest], 409);
        }
        $service->updatePerson($employee->person, $data, $employee);

        return app(EmployeePresenter::class)->profile($r->user(), $employee->refresh());
    }

    public function assignments(Request $r, string $uuid, AssignmentService $assignments)
    {
        $employee = $this->employee($r, $uuid);
        abort_unless($this->access()->canView($r->user(), 'job.department', $employee), 404);

        return ['data' => $assignments->history($employee)];
    }

    public function changeAssignment(Request $r, string $uuid, AssignmentService $assignments)
    {
        $employee = $this->employee($r, $uuid, 'people.manage', false);
        $data = $r->validate(['effective_from' => 'required|date_format:Y-m-d', 'change_type' => 'nullable|in:transfer,promotion,demotion,reorganization,correction,update', 'reason' => 'nullable|string|max:255', 'comments' => 'nullable|string|max:2000'] + EmployeeRules::assignment());
        $row = $assignments->change($employee, collect($data)->only(array_merge(array_keys(EmployeeRules::assignment())))->all(), $data['effective_from'], collect($data)->only(['change_type', 'reason', 'comments'])->all());

        return response()->json($row, 201);
    }

    public function relationships(Request $r, string $uuid, ReportingService $reporting)
    {
        $employee = $this->employee($r, $uuid);
        abort_unless($this->access()->canView($r->user(), 'job.manager', $employee), 404);

        return ['data' => $reporting->history($employee)];
    }

    public function changeRelationship(Request $r, string $uuid, ReportingService $reporting)
    {
        $employee = $this->employee($r, $uuid, 'people.manage', false);
        $data = $r->validate(['type' => 'required|string|max:40', 'manager_uuid' => 'nullable|uuid', 'effective_from' => 'required|date_format:Y-m-d', 'reason' => 'nullable|string|max:255']);
        $manager = ! empty($data['manager_uuid']) ? Employee::query()->where('uuid', $data['manager_uuid'])->firstOrFail() : null;
        $reporting->assign($employee, $manager, $data['type'], $data['effective_from'], $data['reason'] ?? null);

        return ['data' => $reporting->history($employee)];
    }

    public function changeStatus(Request $r, string $uuid, StatusService $status)
    {
        $employee = $this->employee($r, $uuid, 'people.manage', false);
        $data = $r->validate(['status' => ['required', Rule::in(array_keys(StatusService::LABELS))], 'reason_code' => 'nullable|string|max:60', 'reason' => 'nullable|string|max:1000', 'effective_date' => 'nullable|date_format:Y-m-d']);
        $employee = $status->transition($employee, $data['status'], $data);

        return ['uuid' => $employee->uuid, 'status' => $employee->status, 'allowed' => $status->allowed($employee->status), 'history' => $status->history($employee)];
    }

    public function statusHistory(Request $r, string $uuid, StatusService $status)
    {
        $employee = $this->employee($r, $uuid);
        abort_unless($this->access()->canView($r->user(), 'employee.status', $employee), 404);

        return ['allowed' => $status->allowed($employee->status), 'data' => $status->history($employee)];
    }

    public function numberingPreview(Request $r, NumberingService $numbering)
    {
        $context = $r->validate(['employee_type_id' => 'nullable|integer', 'legal_entity_id' => 'nullable|integer', 'location_id' => 'nullable|integer', 'worker_type' => 'nullable|string', 'country' => 'nullable|string|size:2']);

        return ['mode' => $numbering->config()['mode'], 'next' => $numbering->isManual() ? null : $numbering->preview($context)];
    }
}
