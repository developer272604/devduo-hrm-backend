<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Audit\Audit;
use App\Domain\Leave\Models\LeaveDocumentRule;
use App\Domain\Leave\Models\LeaveOptionalHolidayPool;
use App\Domain\Leave\Models\LeavePolicyVersion;
use App\Domain\Leave\Models\LeaveRestriction;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\Units;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Restriction rules, document rules and optional/floating holiday pools: configuration only (evaluation is the requests workstream). */
final class RuleConfigService
{
    public const RESTRICTION_KINDS = ['blackout', 'max_concurrent', 'min_staffing', 'notice', 'consecutive', 'eligibility', 'backdate', 'future_limit'];

    public const ACTIONS = ['warn', 'block', 'escalate'];

    // ---- Restrictions ---------------------------------------------------------------------------

    public function createRestriction(array $d, ?User $actor): LeaveRestriction
    {
        $row = $this->restrictionRow($d);
        $r = LeaveRestriction::create($row + ['created_by' => $actor?->id]);
        Audit::record('created', 'leave', 'leave_restriction:'.$r->uuid, ['kind' => $r->kind, 'action' => $r->action]);

        return $r;
    }

    public function updateRestriction(LeaveRestriction $r, array $d): LeaveRestriction
    {
        $base = $r->only(['name', 'kind', 'action', 'exempt_statutory', 'target_type', 'target_id', 'start_date', 'end_date', 'params', 'active']);
        $base['leave_type'] = $r->leave_type_id ? LeaveType::query()->whereKey($r->leave_type_id)->value('uuid') : null;
        $r->fill($this->restrictionRow(array_merge($base, $d)))->save();
        Audit::record('updated', 'leave', 'leave_restriction:'.$r->uuid, array_keys($d));

        return $r;
    }

    public function deleteRestriction(LeaveRestriction $r): void
    {
        Audit::record('deleted', 'leave', 'leave_restriction:'.$r->uuid, ['kind' => $r->kind]);
        $r->delete();
    }

    private function restrictionRow(array $d): array
    {
        if (! in_array($d['kind'] ?? null, self::RESTRICTION_KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Kind must be one of '.implode(', ', self::RESTRICTION_KINDS).'.']);
        }
        $action = $d['action'] ?? 'warn';
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Action must be one of '.implode(', ', self::ACTIONS).'.']);
        }
        [$targetType, $targetId] = Targets::normalize($d['target_type'] ?? 'tenant', $d['target_id'] ?? '*');
        $from = $d['start_date'] ?? null;
        $to = $d['end_date'] ?? null;
        if ($d['kind'] === 'blackout' && (! $from || ! $to)) {
            throw ValidationException::withMessages(['start_date' => 'A blackout needs a start and end date.']);
        }
        if ($from && $to && $to < $from) {
            throw ValidationException::withMessages(['end_date' => 'The end date cannot be before the start date.']);
        }
        $params = $d['params'] ?? null;
        if ($params !== null && ! is_array($params)) {
            throw ValidationException::withMessages(['params' => 'Params must be an object.']);
        }
        foreach (['max_concurrent' => 'max', 'min_staffing' => 'min_percent'] as $kind => $key) {
            if ($d['kind'] === $kind && (! isset($params[$key]) || ! is_numeric($params[$key]) || $params[$key] < 0)) {
                throw ValidationException::withMessages(["params.$key" => 'This restriction needs a "'.$key.'" number.']);
            }
        }

        return ['name' => $d['name'], 'kind' => $d['kind'], 'action' => $action, 'exempt_statutory' => (bool) ($d['exempt_statutory'] ?? true), 'target_type' => $targetType, 'target_id' => $targetId,
            'leave_type_id' => ! empty($d['leave_type']) ? $this->type($d['leave_type'])->id : null, 'start_date' => $from, 'end_date' => $to, 'params' => $params, 'active' => (bool) ($d['active'] ?? true)];
    }

    // ---- Document rules -------------------------------------------------------------------------

    public function saveDocumentRule(?LeaveDocumentRule $rule, array $d): LeaveDocumentRule
    {
        $row = $this->documentRow($rule, $d);
        $rule = $rule ? tap($rule)->fill($row) : new LeaveDocumentRule($row);
        $rule->save();
        Audit::record($rule->wasRecentlyCreated ? 'created' : 'updated', 'leave', 'leave_document_rule:'.$rule->id, ['leave_type_id' => $rule->leave_type_id]);

        return $rule;
    }

    public function deleteDocumentRule(LeaveDocumentRule $rule): void
    {
        Audit::record('deleted', 'leave', 'leave_document_rule:'.$rule->id, ['leave_type_id' => $rule->leave_type_id]);
        $rule->delete();
    }

    private function documentRow(?LeaveDocumentRule $existing, array $d): array
    {
        $current = $existing ? [
            'leave_type' => LeaveType::query()->whereKey($existing->leave_type_id)->value('uuid'),
            'policy_version' => $existing->policy_version_id ? LeavePolicyVersion::query()->whereKey($existing->policy_version_id)->value('uuid') : null,
        ] + $existing->only(['required_after_units', 'document_kind', 'mandatory', 'is_sensitive', 'submit_within_days', 'retention_days', 'description', 'active']) : [];
        $d = array_merge($current, $d);
        $type = $this->type($d['leave_type'] ?? '');
        $versionId = null;
        if (! empty($d['policy_version'])) {
            $version = LeavePolicyVersion::query()->where('uuid', $d['policy_version'])->first() ?? throw ValidationException::withMessages(['policy_version' => 'We could not find that policy version.']);
            if ($version->status !== PolicyService::DRAFT) {
                throw ValidationException::withMessages(['policy_version' => 'Document rules tied to a published version are frozen. Tie the rule to a draft version or leave it unversioned.']);
            }
            $versionId = $version->id;
        }
        try {
            $after = Units::strict($d['required_after_units'] ?? 0);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['required_after_units' => 'Use a number with at most 4 decimal places.']);
        }
        if (bccomp($after, '0', 4) < 0) {
            throw ValidationException::withMessages(['required_after_units' => 'Cannot be negative.']);
        }

        return ['leave_type_id' => $type->id, 'policy_version_id' => $versionId, 'required_after_units' => $after, 'document_kind' => $d['document_kind'] ?? 'supporting',
            'mandatory' => (bool) ($d['mandatory'] ?? true), 'is_sensitive' => (bool) ($d['is_sensitive'] ?? $type->is_sensitive), 'submit_within_days' => $d['submit_within_days'] ?? null,
            'retention_days' => $d['retention_days'] ?? null, 'description' => $d['description'] ?? null, 'active' => (bool) ($d['active'] ?? true)];
    }

    // ---- Optional / floating holiday pools --------------------------------------------------------

    public function savePool(?LeaveOptionalHolidayPool $pool, array $d): LeaveOptionalHolidayPool
    {
        $d = array_merge($pool ? $pool->only(['name', 'year', 'max_selection', 'holidays', 'applicability', 'active']) + ['leave_type' => $pool->leave_type_id ? LeaveType::query()->whereKey($pool->leave_type_id)->value('uuid') : null] : [], $d);
        $year = (int) ($d['year'] ?? 0);
        if ($year < 2000 || $year > 2200) {
            throw ValidationException::withMessages(['year' => 'Choose a valid year.']);
        }
        $holidays = [];
        foreach ((array) ($d['holidays'] ?? []) as $i => $h) {
            $date = $h['date'] ?? null;
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw ValidationException::withMessages(["holidays.$i.date" => 'Use a valid date (YYYY-MM-DD).']);
            }
            if ((int) substr($date, 0, 4) !== $year) {
                throw ValidationException::withMessages(["holidays.$i.date" => 'The holiday must fall in '.$year.'.']);
            }
            if (isset($holidays[$date])) {
                throw ValidationException::withMessages(["holidays.$i.date" => 'This date is listed twice.']);
            }
            $holidays[$date] = array_filter(['date' => $date, 'name' => $h['name'] ?? null, 'holiday_uuid' => $h['holiday_uuid'] ?? null], fn ($v) => $v !== null);
        }
        if (! $holidays) {
            throw ValidationException::withMessages(['holidays' => 'Add at least one optional holiday.']);
        }
        ksort($holidays);
        $max = (int) ($d['max_selection'] ?? 1);
        if ($max < 1 || $max > count($holidays)) {
            throw ValidationException::withMessages(['max_selection' => 'Employees must be able to pick at least 1 and at most '.count($holidays).' holidays.']);
        }
        $clash = LeaveOptionalHolidayPool::query()->where('name', $d['name'])->where('year', $year)->when($pool, fn ($q) => $q->where('id', '!=', $pool->id))->exists();
        if ($clash) {
            throw ValidationException::withMessages(['name' => 'A pool with this name already exists for '.$year.'.']);
        }
        $row = ['name' => $d['name'], 'year' => $year, 'max_selection' => $max, 'leave_type_id' => ! empty($d['leave_type']) ? $this->type($d['leave_type'])->id : null,
            'holidays' => array_values($holidays), 'applicability' => $d['applicability'] ?? null, 'active' => (bool) ($d['active'] ?? true)];
        $pool = $pool ? tap($pool)->fill($row) : new LeaveOptionalHolidayPool($row);
        $pool->save();
        Audit::record($pool->wasRecentlyCreated ? 'created' : 'updated', 'leave', 'leave_optional_pool:'.$pool->uuid, ['year' => $year, 'max' => $max]);

        return $pool;
    }

    public function deletePool(LeaveOptionalHolidayPool $pool): void
    {
        Audit::record('deleted', 'leave', 'leave_optional_pool:'.$pool->uuid, ['name' => $pool->name]);
        $pool->delete();
    }

    private function type(string $ref): LeaveType
    {
        return LeaveType::query()->where('uuid', $ref)->orWhere('code', $ref)->first() ?? throw ValidationException::withMessages(['leave_type' => 'We could not find that leave type.']);
    }
}
