<?php

namespace App\Domain\Payroll\Runs;

use App\Domain\Access\DataScope;
use App\Domain\Payroll\Models\PayrollGroup;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Who should be paid": employees whose payroll-group reference (employee_policy_references.kind = payroll_group, the Phase 3 source of truth)
 * covers part of the period AND who are employed on at least one of those days. Joiners, leavers and mid-period transfers get a pay window
 * (pay_from..pay_to) so only days inside the group are paid by this run. Data scope is applied for the actor; the count outside scope is reported.
 */
final class RosterBuilder
{
    /**
     * @param  array<int,string>|null  $onlyUuids  off-cycle / correction runs name their employees explicitly
     * @return array{rows:array<int,array<string,mixed>>,out_of_scope:int,skipped:array<int,array<string,string>>}
     */
    public function build(PayrollGroup $group, PayrollPeriod $period, ?User $actor = null, ?array $onlyUuids = null): array
    {
        $start = substr((string) $period->period_start, 0, 10);
        $end = substr((string) $period->period_end, 0, 10);
        $refs = DB::table('employee_policy_references')->where('tenant_id', $group->tenant_id)->where('kind', 'payroll_group')->where('reference_id', $group->id)
            ->whereDate('effective_from', '<=', $end)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start))->get()->groupBy('employee_id');

        $employees = Employee::query()->whereIn('id', $refs->keys()->all() ?: [0])->when($onlyUuids !== null, fn ($q) => $q->whereIn('uuid', $onlyUuids ?: ['-']))->get();
        $inScope = $actor ? app(DataScope::class)->constrain(Employee::query()->whereIn('id', $employees->pluck('id')->all() ?: [0]), $actor, 'payroll.run')->pluck('employees.id')->all() : $employees->pluck('id')->all();

        $rows = [];
        $skipped = [];
        $outOfScope = 0;
        foreach ($employees as $e) {
            if (! in_array($e->id, $inScope, true)) {
                $outOfScope++;

                continue;
            }
            $empStart = ($e->employment_start_date ?? $e->joined_on)?->format('Y-m-d') ?? $start;
            $empEnd = $e->separated_on?->format('Y-m-d');
            // union span of the group references clipped to the period (gaps are not paid by this group)
            $from = null;
            $to = null;
            foreach ($refs[$e->id] as $r) {
                $rf = max(substr((string) $r->effective_from, 0, 10), $start);
                $rt = min($r->effective_to ? substr((string) $r->effective_to, 0, 10) : $end, $end);
                $from = $from === null ? $rf : min($from, $rf);
                $to = $to === null ? $rt : max($to, $rt);
            }
            $payFrom = max($from, $empStart);
            $payTo = $empEnd ? min($to, $empEnd) : $to;
            if ($payFrom > $payTo) {
                $skipped[] = ['employee_uuid' => $e->uuid, 'reason' => 'Not employed within the group during this period.'];

                continue;
            }
            $rows[] = ['employee_id' => $e->id, 'employee_uuid' => $e->uuid, 'snapshot' => [
                'pay_from' => $payFrom, 'pay_to' => $payTo, 'joiner' => $empStart > $start && $empStart <= $end, 'leaver' => $empEnd !== null && $empEnd >= $start && $empEnd < $end,
                'transfer_in' => $from > $start && $empStart <= $from, 'transfer_out' => $to < $end && ($empEnd === null || $empEnd > $to), 'legal_entity_id' => $e->assignmentOn($payTo)?->legal_entity_id,
            ]];
        }

        return ['rows' => $rows, 'out_of_scope' => $outOfScope, 'skipped' => $skipped];
    }
}
