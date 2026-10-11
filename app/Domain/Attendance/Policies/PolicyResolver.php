<?php

namespace App\Domain\Attendance\Policies;

use App\Domain\Attendance\Models\AttendancePolicy;
use App\Domain\Attendance\Models\AttendancePolicyAssignment;
use App\Domain\Attendance\Models\AttendancePolicyVersion;
use App\Domain\Attendance\Scheduling\AssignmentResolver;
use App\Domain\Attendance\Scheduling\OrgContext;
use App\Domain\Attendance\Scheduling\Specificity;

/**
 * Which attendance policy VERSION applies to an employee on an attendance date.
 *
 * Ranking is identical to shift assignments: specificity (employee 100 ... tenant 0), then is_override, priority,
 * effective_from, id. Org attributes are read AS OF the date (never "now"). The winning assignment's policy must
 * be active and have a published/retired version whose effective range covers the date; otherwise the next
 * candidate is considered (and the skip is explained).
 */
final class PolicyResolver
{
    /** @return array{policy:AttendancePolicy,version:AttendancePolicyVersion,assignment:AttendancePolicyAssignment,considered:array<int,array<string,mixed>>}|null */
    public function resolve(int $employeeId, string $date, ?OrgContext $org = null): ?array
    {
        return $this->resolveWithTrace($employeeId, $date, $org)['match'];
    }

    /** Rules of the effective policy version on the date (empty when no policy applies); used by capture and request rules. */
    public function rulesFor(int $employeeId, string $date): array
    {
        return (array) ($this->resolve($employeeId, $date)['version']->rules ?? []);
    }

    /**
     * @return array{match:?array,considered:array<int,array<string,mixed>>,context:array<string,mixed>}
     */
    public function resolveWithTrace(int $employeeId, string $date, ?OrgContext $org = null): array
    {
        $org ??= OrgContext::load([$employeeId], $date, $date);
        $context = $org->at($employeeId, $date);
        $rows = AttendancePolicyAssignment::query()->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->get();
        $candidates = [];
        foreach ($rows as $row) {
            $array = $row->getAttributes();
            if (AssignmentResolver::matches($array, $context, $date)) {
                $candidates[] = $row;
            }
        }
        usort($candidates, fn ($a, $b) => AssignmentResolver::sortKey($b->getAttributes()) <=> AssignmentResolver::sortKey($a->getAttributes()));

        $considered = [];
        $match = null;
        foreach ($candidates as $assignment) {
            $policy = AttendancePolicy::query()->find($assignment->policy_id);
            $entry = ['assignment_id' => $assignment->id, 'policy_id' => $assignment->policy_id, 'policy' => $policy?->name, 'target_type' => $assignment->target_type, 'target_id' => $assignment->target_id,
                'rank' => Specificity::rank($assignment->target_type), 'is_override' => (bool) $assignment->is_override, 'priority' => (int) $assignment->priority, 'effective_from' => $assignment->effective_from];
            if (! $policy || ! $policy->active) {
                $considered[] = $entry + ['outcome' => 'skipped', 'reason' => 'The policy is inactive.'];

                continue;
            }
            $version = $this->versionOn($policy->id, $date);
            if (! $version) {
                $considered[] = $entry + ['outcome' => 'skipped', 'reason' => 'The policy has no published version in effect on this date.'];

                continue;
            }
            $version->setRelation('policy', $policy);
            if ($match === null) {
                $match = ['policy' => $policy, 'version' => $version, 'assignment' => $assignment, 'considered' => []];
                $considered[] = $entry + ['outcome' => 'selected', 'reason' => 'Highest-ranked applicable assignment.', 'version' => $version->version];
            } else {
                $considered[] = $entry + ['outcome' => 'not_used', 'reason' => 'A higher-ranked assignment applies.'];
            }
        }
        if ($match) {
            $match['considered'] = $considered;
        }

        return ['match' => $match, 'considered' => $considered, 'context' => $context];
    }

    /** The published (or since retired) version whose effective range covers the date. Never two: publishing prevents overlaps. */
    public function versionOn(int $policyId, string $date): ?AttendancePolicyVersion
    {
        return AttendancePolicyVersion::query()->where('policy_id', $policyId)->whereIn('status', [AttendancePolicyVersion::PUBLISHED, AttendancePolicyVersion::RETIRED])
            ->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }
}
