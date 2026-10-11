<?php

namespace App\Domain\People\Operations\Bulk;

use App\Domain\People\Models\Assignment;
use App\Domain\People\Models\Employee;
use App\Domain\People\Operations\BulkAction;
use App\Domain\People\Operations\DescribesBulkAction;
use App\Domain\People\Services\AssignmentService;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Effective-dated change of one organization dimension (department, location, employee type). */
class AssignmentAction implements BulkAction, DescribesBulkAction
{
    public function __construct(private string $key, private string $column, private string $label) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Assign '.strtolower($this->label);
    }

    public function description(): string
    {
        return 'Change the '.strtolower($this->label).' of the selected employees from an effective date.';
    }

    public function permission(): string
    {
        return 'people.manage';
    }

    public function validate(array $params): array
    {
        $data = Validator::make($params, [$this->column => 'required|integer', 'effective_from' => 'nullable|date_format:Y-m-d', 'reason' => 'nullable|string|max:255'],
            [$this->column.'.required' => 'Choose a '.strtolower($this->label).'.'])->validate();
        $data['effective_from'] ??= TenantClock::today();

        return $data;
    }

    public function preview(User $user, Collection $employees, array $params): array
    {
        $table = Assignment::DIMENSIONS[$this->column];
        $target = DB::table($table)->where('tenant_id', app(TenantContext::class)->id)->where('id', $params[$this->column])->first();
        $out = [];
        foreach ($employees as $e) {
            $out[$e->id] = (! $target || ! $target->active)
                ? ['status' => 'error', 'reason' => 'Choose an active '.strtolower($this->label).' from your organization.']
                : $this->check($e, $params, $target->name);
        }

        return $out;
    }

    private function check(Employee $e, array $params, string $name): array
    {
        $from = $params['effective_from'];
        if ($e->status === 'archived') {
            return ['status' => 'error', 'reason' => 'Archived employees cannot be changed.'];
        }
        if ($from < $e->joined_on->toDateString()) {
            return ['status' => 'error', 'reason' => 'The effective date is before this employee joined ('.$e->joined_on->toDateString().').'];
        }
        if ($e->separated_on && $from > $e->separated_on->toDateString()) {
            return ['status' => 'error', 'reason' => 'The effective date is after the last working date of this employee.'];
        }
        if (DB::table('employee_assignments')->where('employee_id', $e->id)->where('effective_from', $from)->exists()) {
            return ['status' => 'error', 'reason' => 'A job record already starts on '.$from.'. Choose a different effective date.'];
        }
        if ((int) ($e->assignmentOn($from)?->{$this->column}) === (int) $params[$this->column]) {
            return ['status' => 'skipped', 'reason' => 'Already assigned to '.$name.'.'];
        }

        return ['status' => 'ok'];
    }

    public function apply(User $user, Employee $employee, array $params): void
    {
        app(AssignmentService::class)->change($employee, [$this->column => (int) $params[$this->column]], $params['effective_from'], ['reason' => $params['reason'] ?? 'Bulk update', 'source' => 'bulk']);
    }
}
