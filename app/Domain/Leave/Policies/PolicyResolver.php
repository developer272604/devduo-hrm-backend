<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Attendance\Scheduling\AssignmentResolver;
use App\Domain\Attendance\Scheduling\OrgContext;
use App\Domain\Attendance\Scheduling\Specificity;
use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Eligibility\EligibilityEvaluator;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeavePolicyTypeRule;
use App\Domain\Leave\Models\LeavePolicyVersion;
use App\Domain\Leave\Models\LeaveType;

/**
 * Which leave policy VERSION applies to an employee on a date. Same ranking as the attendance PolicyResolver / shift
 * assignments: specificity (employee 100 ... tenant 0), is_override, priority, effective_from, id. Organization attributes
 * are read AS OF the date, never "now". A winning assignment whose policy is inactive or has no published version covering
 * the date is skipped (and the skip is explained). Ties on the first four keys are reported as ambiguous.
 */
final class PolicyResolver implements LeavePolicyResolver
{
    public function resolve(int $employeeId, string $date): ?array
    {
        return $this->resolveWithTrace($employeeId, $date)['match'];
    }

    public function rulesFor(int $employeeId, int $leaveTypeId, string $date): ?array
    {
        return $this->explainType($employeeId, $leaveTypeId, $date)['result'];
    }

    /**
     * Rules for a type on the date plus the reason when it is not granted.
     *
     * @return array{result:?array,reason:string}
     */
    public function explainType(int $employeeId, int $leaveTypeId, string $date, ?OrgContext $org = null): array
    {
        $trace = $this->resolveWithTrace($employeeId, $date, $org);
        $match = $trace['match'];
        if (! $match) {
            return ['result' => null, 'reason' => 'No leave policy applies to this employee on '.$date.'.'];
        }
        $type = LeaveType::query()->find($leaveTypeId);
        if (! $type || ! $type->active) {
            return ['result' => null, 'reason' => 'The leave type is inactive or does not exist.'];
        }
        $row = LeavePolicyTypeRule::query()->where('policy_version_id', $match['version']->id)->where('leave_type_id', $leaveTypeId)->first();
        if (! $row) {
            return ['result' => null, 'reason' => 'The '.$match['policy']->name.' policy (v'.$match['version']->version.') does not grant this leave type.'];
        }
        $typeRules = (array) $row->rules;
        $context = $trace['context'];
        $applicability = EligibilityEvaluator::applicability($context, (array) ($type->config ?? []), (array) ($typeRules['eligibility'] ?? []));
        if ($applicability !== null) {
            return ['result' => null, 'reason' => $applicability];
        }
        $rules = array_merge((array) $match['version']->rules, $typeRules);
        $eligibility = app(EligibilityEvaluator::class)->evaluate($employeeId, $typeRules['eligibility'] ?? [], $date);

        return ['result' => ['version_id' => $match['version']->id, 'policy_id' => $match['policy']->id, 'type_id' => $leaveTypeId, 'rules' => $rules,
            'reason' => 'Granted by '.$match['policy']->name.' v'.$match['version']->version.' via the '.str_replace('_', ' ', $match['assignment']->target_type).' assignment.',
            'eligibility' => $eligibility, 'policy_rules' => (array) $match['version']->rules, 'type_rules' => $typeRules], 'reason' => 'granted'];
    }

    /** @return array{match:?array,considered:array<int,array<string,mixed>>,context:array<string,mixed>,ambiguous:bool} */
    public function resolveWithTrace(int $employeeId, string $date, ?OrgContext $org = null): array
    {
        $org ??= OrgContext::load([$employeeId], $date, $date);
        $context = $org->at($employeeId, $date);
        $candidates = [];
        foreach (LeavePolicyAssignment::query()->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->get() as $row) {
            if (AssignmentResolver::matches($row->getAttributes(), $context, $date)) {
                $candidates[] = $row;
            }
        }
        usort($candidates, fn ($a, $b) => AssignmentResolver::sortKey($b->getAttributes()) <=> AssignmentResolver::sortKey($a->getAttributes()));

        $considered = [];
        $match = null;
        $topKey = null;
        $ambiguous = false;
        foreach ($candidates as $assignment) {
            $policy = LeavePolicy::query()->find($assignment->policy_id);
            $entry = ['assignment_id' => $assignment->uuid, 'policy_id' => $assignment->policy_id, 'policy' => $policy?->name, 'target_type' => $assignment->target_type, 'target_id' => $assignment->target_id,
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
            $key = array_slice(AssignmentResolver::sortKey($assignment->getAttributes()), 0, 4);
            if ($match === null) {
                $match = ['policy' => $policy, 'version' => $version, 'assignment' => $assignment, 'considered' => []];
                $topKey = $key;
                $considered[] = $entry + ['outcome' => 'selected', 'reason' => 'Highest-ranked applicable assignment.', 'version' => $version->version];
            } else {
                $tie = $key === $topKey;
                $ambiguous = $ambiguous || $tie;
                $considered[] = $entry + ['outcome' => 'not_used', 'reason' => $tie ? 'Ties with the selected assignment on specificity, override and priority; the later-created one won.' : 'A higher-ranked assignment applies.'];
            }
        }
        if ($match) {
            $match['considered'] = $considered;
        }

        return ['match' => $match, 'considered' => $considered, 'context' => $context, 'ambiguous' => $ambiguous];
    }

    /** The published (or since retired) version whose effective range covers the date. Never two: publishing prevents overlaps. */
    public function versionOn(int $policyId, string $date): ?LeavePolicyVersion
    {
        return LeavePolicyVersion::query()->where('policy_id', $policyId)->whereIn('status', array_merge(PolicyService::LIVE, [PolicyService::RETIRED]))
            ->where('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->orderByDesc('effective_from')->orderByDesc('version')->first();
    }
}
