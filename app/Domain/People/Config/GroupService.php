<?php

namespace App\Domain\People\Config;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\EmployeeGroup;
use App\Domain\People\Models\EmployeeGroupMember;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Employee groups: named sets of employees used for the custom-group data scope (via user_data_scopes grants of kind
 * employee_group) and, later, time-policy assignment. Membership may carry effective dates.
 */
final class GroupService
{
    public const MAX_BATCH = 500;

    public function present(EmployeeGroup $group, ?int $members = null): array
    {
        return ['id' => $group->id, 'name' => $group->name, 'code' => $group->code, 'description' => $group->description, 'active' => $group->active,
            'member_count' => $members ?? $group->members_count ?? EmployeeGroupMember::query()->where('group_id', $group->id)->count(),
            'created_at' => $group->created_at?->toIso8601String(), 'updated_at' => $group->updated_at?->toIso8601String()];
    }

    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = EmployeeGroup::query()->withCount('members')->orderBy('name');
        if (! empty($filters['q'])) {
            $term = '%'.addcslashes(mb_substr((string) $filters['q'], 0, 100), '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }
        if (isset($filters['active']) && $filters['active'] !== '') {
            $query->where('active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->paginate($perPage)->through(fn ($g) => $this->present($g));
    }

    public function create(array $input): EmployeeGroup
    {
        $tenant = app(TenantContext::class)->id;
        $code = $input['code'] ?? null;
        if ($code === null && isset($input['name']) && is_string($input['name'])) {
            $code = substr(Str::slug($input['name'], '_'), 0, 40) ?: null;
        }
        $data = Validator::make(['code' => $code] + $input, [
            'name' => ['required', 'string', 'max:150'], 'code' => ['required', 'regex:/^[a-z][a-z0-9_\-]{1,39}$/', Rule::unique('employee_groups', 'code')->where('tenant_id', $tenant)],
            'description' => ['nullable', 'string', 'max:1000'], 'active' => ['sometimes', 'boolean'],
        ], ['code.regex' => 'Use a code of 2 to 40 lowercase letters, digits, - or _, starting with a letter.', 'code.unique' => 'A group with this code already exists.'])->validate();

        return DB::transaction(function () use ($data) {
            $group = EmployeeGroup::create(['name' => $data['name'], 'code' => $data['code'], 'description' => $data['description'] ?? null, 'active' => $data['active'] ?? true]);
            Audit::record('created', 'people', 'employee-group:'.$group->id, ['code' => $group->code, 'name' => $group->name]);

            return $group;
        });
    }

    public function update(EmployeeGroup $group, array $input): EmployeeGroup
    {
        if (array_key_exists('code', $input) && $input['code'] !== $group->code) {
            throw ValidationException::withMessages(['code' => ['A group code cannot be changed once created.']]);
        }
        $data = Validator::make($input, ['name' => ['sometimes', 'required', 'string', 'max:150'], 'description' => ['sometimes', 'nullable', 'string', 'max:1000'], 'active' => ['sometimes', 'boolean']])->validate();

        return DB::transaction(function () use ($group, $data) {
            $before = $group->only(['name', 'description', 'active']);
            $group->fill($data)->save();
            Audit::record('updated', 'people', 'employee-group:'.$group->id, ['code' => $group->code, 'before' => $before, 'after' => $group->only(array_keys($before))]);

            return $group;
        });
    }

    public function delete(EmployeeGroup $group): void
    {
        abort_if(EmployeeGroupMember::query()->where('group_id', $group->id)->exists(), 422, 'This group still has members. Remove them first, or deactivate the group instead.');
        abort_if(DB::table('user_data_scopes')->where('tenant_id', $group->tenant_id)->where('kind', 'employee_group')->where('reference_id', $group->id)->exists(), 422, 'This group is used by data-scope grants. Revoke them first, or deactivate the group instead.');
        DB::transaction(function () use ($group) {
            $group->delete();
            Audit::record('deleted', 'people', 'employee-group:'.$group->id, ['code' => $group->code]);
        });
    }

    /** Members the viewer may see (their data scope applies, so a group never reveals people outside the viewer's reach). */
    public function members(User $viewer, EmployeeGroup $group, int $perPage): LengthAwarePaginator
    {
        $query = DB::table('employee_group_members as m')->join('employees as e', 'e.id', '=', 'm.employee_id')->join('people as p', 'p.id', '=', 'e.person_id')
            ->where('m.tenant_id', $group->tenant_id)->where('m.group_id', $group->id)->whereNull('e.deleted_at');
        app(DataScope::class)->constrain($query, $viewer, 'people.view', 'e.id');

        return $query->orderBy('p.display_name')->orderBy('e.id')->select(['e.uuid', 'e.employee_number', 'p.display_name', 'e.status', 'm.effective_from', 'm.effective_to'])
            ->paginate($perPage)->through(fn ($row) => ['employee_uuid' => $row->uuid, 'employee_number' => $row->employee_number, 'display_name' => $row->display_name, 'status' => $row->status,
                'effective_from' => $row->effective_from, 'effective_to' => $row->effective_to]);
    }

    /** @return array{added:int,updated:int} */
    public function addMembers(EmployeeGroup $group, array $input): array
    {
        $data = Validator::make($input, [
            'employees' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH], 'employees.*' => ['required', 'uuid'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ], ['employees.required' => 'Choose at least one employee.', 'employees.*.uuid' => 'Employees must be given by their public id.', 'effective_to.after_or_equal' => 'The end date cannot be before the start date.'])->validate();
        $uuids = array_values(array_unique(array_map('strtolower', $data['employees'])));
        $employees = Employee::query()->whereIn('uuid', $uuids)->get(['id', 'uuid']);
        if ($employees->count() !== count($uuids)) {
            throw ValidationException::withMessages(['employees' => [(count($uuids) - $employees->count()).' of the selected employees were not found in this company.']]);
        }

        return DB::transaction(function () use ($group, $data, $employees) {
            $added = 0;
            $updated = 0;
            foreach ($employees as $employee) {
                $member = EmployeeGroupMember::query()->where('group_id', $group->id)->where('employee_id', $employee->id)->first();
                $dates = ['effective_from' => $data['effective_from'] ?? null, 'effective_to' => $data['effective_to'] ?? null];
                if ($member) {
                    $member->update($dates);
                    $updated++;
                } else {
                    EmployeeGroupMember::create(['group_id' => $group->id, 'employee_id' => $employee->id] + $dates);
                    $added++;
                }
            }
            Audit::record('members_added', 'people', 'employee-group:'.$group->id, ['code' => $group->code, 'added' => $added, 'updated' => $updated, 'employees' => $employees->pluck('uuid')->all()]);

            return ['added' => $added, 'updated' => $updated];
        });
    }

    public function removeMember(EmployeeGroup $group, string $employeeUuid): void
    {
        $employee = Employee::query()->where('uuid', $employeeUuid)->firstOrFail();
        $member = EmployeeGroupMember::query()->where('group_id', $group->id)->where('employee_id', $employee->id)->firstOrFail();
        DB::transaction(function () use ($group, $member, $employee) {
            $member->delete();
            Audit::record('member_removed', 'people', 'employee-group:'.$group->id, ['code' => $group->code, 'employee' => $employee->uuid]);
        });
    }
}
