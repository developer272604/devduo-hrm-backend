<?php

namespace App\Domain\Leave\Contracts;

/**
 * Resolves which leave policy VERSION (and per-type rules) applies to an employee on a date, using the same deterministic
 * precedence as attendance (employee > group > department/location/grade/entity/country > tenant; override, priority,
 * effective_from, id). Implemented by workstream A (Leave/Policies/PolicyResolver). Org attributes are read AS OF the date.
 */
interface LeavePolicyResolver
{
    /** @return array{policy:\App\Domain\Leave\Models\LeavePolicy,version:\App\Domain\Leave\Models\LeavePolicyVersion,assignment:\App\Domain\Leave\Models\LeavePolicyAssignment,considered:array<int,array<string,mixed>>}|null */
    public function resolve(int $employeeId, string $date): ?array;

    /**
     * Rules for one leave type on the date (policy-wide rules merged with leave_policy_type_rules.rules), or null when the
     * type is not granted to the employee. Keys: see docs/api/leave-foundation.md "Rule document".
     *
     * @return array{version_id:int,policy_id:int,type_id:int,rules:array<string,mixed>,reason:string}|null
     */
    public function rulesFor(int $employeeId, int $leaveTypeId, string $date): ?array;
}
