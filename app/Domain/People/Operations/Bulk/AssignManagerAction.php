<?php

namespace App\Domain\People\Operations\Bulk;

use App\Domain\Access\DataScope;
use App\Domain\People\Models\Employee;
use App\Domain\People\Operations\BulkAction;
use App\Domain\People\Operations\DescribesBulkAction;
use App\Domain\People\Services\ReportingService;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

class AssignManagerAction implements BulkAction, DescribesBulkAction
{
    public function key(): string
    {
        return 'assign_manager';
    }

    public function label(): string
    {
        return 'Assign manager';
    }

    public function description(): string
    {
        return 'Set the reporting manager of the selected employees from an effective date.';
    }

    public function permission(): string
    {
        return 'people.manage';
    }

    public function validate(array $params): array
    {
        $data = Validator::make($params, ['manager_uuid' => 'required|uuid', 'effective_from' => 'nullable|date_format:Y-m-d', 'reason' => 'nullable|string|max:255'], ['manager_uuid.required' => 'Choose a manager.'])->validate();
        $data['effective_from'] ??= TenantClock::today();

        return $data;
    }

    private function manager(User $user, array $params): ?Employee
    {
        $q = Employee::query()->where('uuid', $params['manager_uuid']);
        app(DataScope::class)->constrain($q, $user, 'people.view', 'employees.id');

        return $q->first();
    }

    public function preview(User $user, Collection $employees, array $params): array
    {
        $manager = $this->manager($user, $params);
        $reporting = app(ReportingService::class);
        $chain = $manager ? $reporting->getReportingChain($manager, 'primary', $params['effective_from'])->pluck('id')->all() : [];
        $out = [];
        foreach ($employees as $e) {
            $out[$e->id] = match (true) {
                ! $manager => ['status' => 'error', 'reason' => 'The chosen manager was not found.'],
                in_array($manager->status, ['separated', 'archived', 'inactive'], true) => ['status' => 'error', 'reason' => 'Choose a manager who is currently employed.'],
                $manager->id === $e->id => ['status' => 'error', 'reason' => 'An employee cannot report to themselves.'],
                in_array($e->id, $chain, true) => ['status' => 'error', 'reason' => 'This would create a circular reporting line.'],
                $params['effective_from'] < $e->joined_on->toDateString() => ['status' => 'error', 'reason' => 'The effective date is before this employee joined.'],
                $reporting->getManager($e, 'primary', $params['effective_from'])?->id === $manager->id => ['status' => 'skipped', 'reason' => 'Already reports to this manager.'],
                default => ['status' => 'ok'],
            };
        }

        return $out;
    }

    public function apply(User $user, Employee $employee, array $params): void
    {
        $manager = $this->manager($user, $params) ?? abort(422, 'The chosen manager was not found.');
        app(ReportingService::class)->assign($employee, $manager, 'primary', $params['effective_from'], $params['reason'] ?? 'Bulk update', 'bulk');
    }
}
