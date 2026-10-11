<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Scheduling\OrgContext;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns a processing scope ({employee_ids|department_id|location_id|employee_group_id|legal_entity_id|tenant} + from/to)
 * into (employee, date) pairs. Membership is evaluated AS OF EACH DATE (a transferred employee belongs to the department on
 * the dates they were in it) and only employed employees are included (no preboarding, archived, before joining or after leaving).
 */
final class ScopeExpander
{
    public const CHUNK = 100;

    public const MAX_DAYS = 366;

    public function normalize(array $scope): array
    {
        $today = TenantClock::today();
        $from = $scope['from'] ?? $scope['date'] ?? $today;
        $to = $scope['to'] ?? $scope['date'] ?? $from;
        foreach (['from' => $from, 'to' => $to] as $field => $value) {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                throw ValidationException::withMessages([$field => 'Use the format YYYY-MM-DD for dates.']);
            }
        }
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'The end date cannot be before the start date.']);
        }
        if (CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Choose a range of at most '.self::MAX_DAYS.' days.']);
        }
        $out = ['from' => $from, 'to' => $to];
        if (! empty($scope['employee_ids'])) {
            $out['employee_ids'] = array_values(array_unique(array_map('intval', (array) $scope['employee_ids'])));
            sort($out['employee_ids']);
        }
        foreach (['department_id', 'location_id', 'employee_group_id', 'legal_entity_id', 'payroll_group_id'] as $key) {
            if (! empty($scope[$key])) {
                $out[$key] = (int) $scope[$key];
            }
        }
        if (! empty($scope['tenant'])) {
            $out['tenant'] = true;
        }
        if (count($out) === 2) {
            throw ValidationException::withMessages(['scope' => 'Choose who to process: employees, a department, a location, an employee group or the whole company.']);
        }

        return $out;
    }

    /** @return array{employees:int,dates:int,employee_days:int} */
    public function count(array $scope): array
    {
        $employees = 0;
        $days = 0;
        $dates = [];
        foreach ($this->expand($scope) as $chunk) {
            foreach ($chunk as $list) {
                $employees++;
                $days += count($list);
                foreach ($list as $d) {
                    $dates[$d] = true;
                }
            }
        }

        return ['employees' => $employees, 'dates' => count($dates), 'employee_days' => $days];
    }

    /** @return \Generator<int,array<int,array<int,string>>> chunks (<= CHUNK employees) of employeeId => ascending dates */
    public function expand(array $scope): \Generator
    {
        $scope = $this->normalize($scope);
        $tenant = app(TenantContext::class)->id;
        $from = $scope['from'];
        $to = $scope['to'];
        $query = DB::table('employees')->where('employees.tenant_id', $tenant)->whereNull('employees.deleted_at')->whereNull('employees.archived_at')->where('employees.status', '!=', 'preboarding')
            ->whereDate('employees.joined_on', '<=', $to)->where(fn ($q) => $q->whereNull('employees.separated_on')->orWhereDate('employees.separated_on', '>=', $from));
        if (isset($scope['employee_ids'])) {
            $query->whereIn('employees.id', $scope['employee_ids']);
        }
        foreach (['department_id', 'location_id', 'legal_entity_id'] as $column) {
            if (isset($scope[$column])) {
                $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('employee_assignments as a')->whereColumn('a.employee_id', 'employees.id')->where('a.'.$column, $scope[$column])
                    ->where('a.effective_from', '<=', $to)->where(fn ($w) => $w->whereNull('a.effective_to')->orWhere('a.effective_to', '>=', $from)));
            }
        }
        if (isset($scope['employee_group_id'])) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('employee_group_members as m')->whereColumn('m.employee_id', 'employees.id')->where('m.group_id', $scope['employee_group_id'])
                ->where(fn ($w) => $w->whereNull('m.effective_from')->orWhere('m.effective_from', '<=', $to))->where(fn ($w) => $w->whereNull('m.effective_to')->orWhere('m.effective_to', '>=', $from)));
        }
        if (isset($scope['payroll_group_id'])) {
            $query->whereExists(fn ($q) => $q->select(DB::raw(1))->from('employee_policy_references as r')->whereColumn('r.employee_id', 'employees.id')->where('r.kind', 'payroll_group')->where('r.reference_id', $scope['payroll_group_id'])
                ->where('r.effective_from', '<=', $to)->where(fn ($w) => $w->whereNull('r.effective_to')->orWhere('r.effective_to', '>=', $from)));
        }
        $dates = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $dates[] = $d->toDateString();
        }
        foreach ($query->orderBy('employees.id')->pluck('employees.id')->chunk(self::CHUNK) as $ids) {
            $org = OrgContext::load($ids->all(), $from, $to);
            $payroll = isset($scope['payroll_group_id']) ? DB::table('employee_policy_references')->where('tenant_id', $tenant)->whereIn('employee_id', $ids->all())->where('kind', 'payroll_group')
                ->where('reference_id', $scope['payroll_group_id'])->get()->groupBy('employee_id') : collect();
            $chunk = [];
            foreach ($ids as $id) {
                $employee = $org->employee($id);
                $list = [];
                foreach ($dates as $date) {
                    if (substr((string) $employee['joined_on'], 0, 10) > $date || ($employee['separated_on'] !== null && substr((string) $employee['separated_on'], 0, 10) < $date)) {
                        continue;
                    }
                    if ($this->matches($scope, $org, $id, $date, $payroll->get($id, collect()))) {
                        $list[] = $date;
                    }
                }
                if ($list) {
                    $chunk[$id] = $list;
                }
            }
            if ($chunk) {
                yield $chunk;
            }
        }
    }

    private function matches(array $scope, OrgContext $org, int $employeeId, string $date, $references): bool
    {
        if (isset($scope['tenant']) || isset($scope['employee_ids'])) {
            return true;
        }
        $context = $org->at($employeeId, $date);
        if (isset($scope['department_id'])) {
            return (string) $context['department'] === (string) $scope['department_id'];
        }
        if (isset($scope['location_id'])) {
            return (string) $context['location'] === (string) $scope['location_id'];
        }
        if (isset($scope['legal_entity_id'])) {
            return (string) $context['legal_entity'] === (string) $scope['legal_entity_id'];
        }
        if (isset($scope['payroll_group_id'])) {
            return $references->contains(fn ($r) => substr($r->effective_from, 0, 10) <= $date && ($r->effective_to === null || substr($r->effective_to, 0, 10) >= $date));
        }
        if (isset($scope['employee_group_id'])) {
            return in_array((string) $scope['employee_group_id'], $context['employee_group'], true);
        }

        return false;
    }
}
