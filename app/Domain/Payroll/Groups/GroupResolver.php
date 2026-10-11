<?php

namespace App\Domain\Payroll\Groups;

use App\Domain\Audit\Audit;
use App\Domain\Payroll\Models\PayrollGroup;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee -> payroll group. Source of truth is employee_policy_references (kind payroll_group), shared with Attendance, so there is no
 * second assignment table. An employee has exactly one group on any date; assigning a new group ends the previous one the day before.
 */
final class GroupResolver
{
    private function refs()
    {
        return DB::table('employee_policy_references')->where('tenant_id', app(TenantContext::class)->id)->where('kind', 'payroll_group');
    }

    public function groupIdFor(int $employeeId, string $date): ?int
    {
        $id = $this->refs()->where('employee_id', $employeeId)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->value('reference_id');

        return $id ? (int) $id : null;
    }

    /** Group segments of an employee overlapping a period (mid-period transfers). @return array<int,array{group_id:int,from:string,to:string}> */
    public function segments(int $employeeId, string $start, string $end): array
    {
        return $this->refs()->where('employee_id', $employeeId)->where('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start))->orderBy('effective_from')->get()
            ->map(fn ($r) => ['group_id' => (int) $r->reference_id, 'from' => max($r->effective_from, $start), 'to' => $r->effective_to === null ? $end : min($r->effective_to, $end)])->all();
    }

    /** Employees whose group is $groupId at any time in [start, end]. @return array<int,int> */
    public function employeeIds(int $groupId, string $start, string $end): array
    {
        return $this->refs()->where('reference_id', $groupId)->where('effective_from', '<=', $end)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $start))->distinct()->pluck('employee_id')->map('intval')->all();
    }

    public function assign(Employee $employee, PayrollGroup $group, string $from, ?string $to = null): void
    {
        if (! $group->active) $this->invalid('group_uuid', 'This payroll group is inactive.');
        if ($to !== null && $to < $from) $this->invalid('effective_to', 'End date cannot be before the start date.');
        $assignment = $employee->assignmentOn($from);
        if ($group->legal_entity_id && $assignment && (int) $assignment->legal_entity_id !== (int) $group->legal_entity_id) $this->invalid('group_uuid', 'Payroll group must match the employee legal entity on the effective date.');
        DB::transaction(function () use ($employee, $group, $from, $to) {
            Employee::whereKey($employee->id)->lockForUpdate()->first();
            $overlap = $this->refs()->where('employee_id', $employee->id)->where('effective_from', '<=', $to ?? '9999-12-31')
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->get();
            foreach ($overlap as $r) {
                if ($r->effective_from >= $from) $this->invalid('effective_from', 'The employee already has a payroll group starting on or after this date. End or correct it first.');
                DB::table('employee_policy_references')->where('id', $r->id)->update(['effective_to' => CarbonImmutable::parse($from)->subDay()->toDateString(), 'updated_at' => now()]);
            }
            DB::table('employee_policy_references')->insert(['tenant_id' => app(TenantContext::class)->id, 'employee_id' => $employee->id, 'kind' => 'payroll_group', 'reference_id' => $group->id,
                'effective_from' => $from, 'effective_to' => $to, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record('group.employee_assigned', 'payroll', $group->uuid, ['employee_uuid' => $employee->uuid, 'from' => $from]);
        });
    }

    /** Employees with more than one group on the same date (data integrity report). @return array<int,array<string,mixed>> */
    public function conflicts(): array
    {
        $rows = $this->refs()->orderBy('employee_id')->orderBy('effective_from')->get()->groupBy('employee_id');
        $out = [];
        foreach ($rows as $employeeId => $list) {
            for ($i = 1; $i < $list->count(); $i++) {
                $prev = $list[$i - 1]; $cur = $list[$i];
                if ($prev->effective_to === null || $prev->effective_to >= $cur->effective_from) $out[] = ['employee_id' => (int) $employeeId, 'groups' => [(int) $prev->reference_id, (int) $cur->reference_id], 'from' => $cur->effective_from];
            }
        }

        return $out;
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
