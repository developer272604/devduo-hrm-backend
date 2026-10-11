<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Attendance\Processing\Canonical;
use App\Domain\Attendance\Scheduling\Specificity;
use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Models\LeaveCycle;
use App\Domain\Leave\Models\LeavePolicy;
use App\Domain\Leave\Models\LeavePolicyAssignment;
use App\Domain\Leave\Models\LeavePolicyTypeRule;
use App\Domain\Leave\Models\LeavePolicyVersion;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Leave policy administration. Versions are effective-dated and IMMUTABLE once published (a change is a clone into a new
 * draft with a change note). Only `status` and `effective_to` of a published version may change, and only by publish/retire
 * of a neighbouring version; the checksum of rules + type rules proves nothing else moved.
 *
 * Stored statuses: draft -> scheduled (published, starts in the future) -> active (in effect) -> retired. Statuses are
 * re-derived from dates by syncStatuses(); the resolver never depends on them (it uses the effective range).
 */
final class PolicyService
{
    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const ACTIVE = 'active';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    /** Statuses of a version that has been published (immutable). */
    public const LIVE = [self::PUBLISHED, self::SCHEDULED, self::ACTIVE];

    public function __construct(private readonly RuleValidator $validator) {}

    // ---- Policies ----------------------------------------------------------------------------

    public function create(array $data, ?User $actor = null): LeavePolicy
    {
        $rules = $data['rules'] ?? [];
        $this->assertPolicyRules($rules);
        $typeRules = $this->resolveTypeRules($data['type_rules'] ?? []);

        return DB::transaction(function () use ($data, $rules, $typeRules, $actor) {
            $policy = LeavePolicy::create(['name' => $data['name'], 'code' => $data['code'], 'description' => $data['description'] ?? null,
                'country' => isset($data['country']) ? strtoupper($data['country']) : null, 'active' => $data['active'] ?? true, 'created_by' => $actor?->id]);
            $version = $this->insertVersion($policy, 1, $rules, $data['effective_from'] ?? TenantClock::today(), $data['effective_to'] ?? null, $data['change_note'] ?? 'Initial version', $actor);
            foreach ($typeRules as $typeId => $tr) {
                LeavePolicyTypeRule::create(['policy_version_id' => $version->id, 'leave_type_id' => $typeId, 'rules' => $tr]);
            }
            Audit::record('created', 'leave', 'leave_policy:'.$policy->uuid, ['code' => $policy->code]);

            return $policy;
        });
    }

    public function update(LeavePolicy $policy, array $data): LeavePolicy
    {
        $policy->fill(array_intersect_key($data, array_flip(['name', 'description', 'active'])));
        if (array_key_exists('country', $data)) {
            $policy->country = $data['country'] ? strtoupper($data['country']) : null;
        }
        $policy->save();
        Audit::record('updated', 'leave', 'leave_policy:'.$policy->uuid, array_keys($data));

        return $policy;
    }

    // ---- Versions ----------------------------------------------------------------------------

    /** Clone (or create) a draft version. Rules/type rules replace the base when given; otherwise the base is copied. */
    public function newVersion(LeavePolicy $policy, array $data, ?User $actor = null): LeavePolicyVersion
    {
        if (blank($data['change_note'] ?? null)) {
            throw ValidationException::withMessages(['change_note' => 'Describe what changed so the history stays understandable.']);
        }
        if (array_key_exists('rules', $data)) {
            $this->assertPolicyRules($data['rules']);
        }
        $typeRules = array_key_exists('type_rules', $data) ? $this->resolveTypeRules($data['type_rules']) : null;

        return DB::transaction(function () use ($policy, $data, $actor, $typeRules) {
            LeavePolicy::query()->whereKey($policy->id)->lockForUpdate()->first();
            $base = isset($data['base_version'])
                ? (LeavePolicyVersion::query()->where('policy_id', $policy->id)->where('version', (int) $data['base_version'])->first() ?? throw ValidationException::withMessages(['base_version' => 'That version does not exist.']))
                : LeavePolicyVersion::query()->where('policy_id', $policy->id)->orderByDesc('version')->first();
            $rules = $data['rules'] ?? ($base?->rules ?? []);
            $next = (int) LeavePolicyVersion::query()->where('policy_id', $policy->id)->max('version') + 1;
            $version = $this->insertVersion($policy, $next, $rules, $data['effective_from'] ?? TenantClock::today(), $data['effective_to'] ?? null, $data['change_note'], $actor);
            $copy = $typeRules ?? ($base ? $base->typeRules()->get()->mapWithKeys(fn ($r) => [$r->leave_type_id => $r->rules])->all() : []);
            foreach ($copy as $typeId => $tr) {
                LeavePolicyTypeRule::create(['policy_version_id' => $version->id, 'leave_type_id' => $typeId, 'rules' => $tr]);
            }

            return $version;
        });
    }

    public function updateDraft(LeavePolicyVersion $version, array $data): LeavePolicyVersion
    {
        $this->assertDraft($version);
        if (array_key_exists('rules', $data)) {
            $this->assertPolicyRules($data['rules']);
            $version->rules = $data['rules'];
        }
        foreach (['effective_from', 'effective_to', 'change_note'] as $field) {
            if (array_key_exists($field, $data)) {
                $version->{$field} = $data[$field];
            }
        }
        $this->assertRange($version->effective_from, $version->effective_to);
        $typeRules = array_key_exists('type_rules', $data) ? $this->resolveTypeRules($data['type_rules']) : null;
        DB::transaction(function () use ($version, $typeRules) {
            $version->save();
            if ($typeRules !== null) {
                LeavePolicyTypeRule::query()->where('policy_version_id', $version->id)->delete();
                foreach ($typeRules as $typeId => $tr) {
                    LeavePolicyTypeRule::create(['policy_version_id' => $version->id, 'leave_type_id' => $typeId, 'rules' => $tr]);
                }
            }
        });
        Audit::record('updated', 'leave', 'leave_policy_version:'.$version->uuid, array_keys($data));

        return $version;
    }

    public function setTypeRule(LeavePolicyVersion $version, LeaveType $type, array $rules): LeavePolicyTypeRule
    {
        $this->assertDraft($version);
        $this->assertTypeRules($type, $rules);
        $row = LeavePolicyTypeRule::query()->updateOrCreate(['policy_version_id' => $version->id, 'leave_type_id' => $type->id], ['rules' => $rules]);
        Audit::record('updated', 'leave', 'leave_policy_version:'.$version->uuid, ['type_rule' => $type->code]);

        return $row;
    }

    public function removeTypeRule(LeavePolicyVersion $version, LeaveType $type): void
    {
        $this->assertDraft($version);
        LeavePolicyTypeRule::query()->where('policy_version_id', $version->id)->where('leave_type_id', $type->id)->delete();
        Audit::record('updated', 'leave', 'leave_policy_version:'.$version->uuid, ['type_rule_removed' => $type->code]);
    }

    public function deleteDraft(LeavePolicyVersion $version): void
    {
        $this->assertDraft($version);
        DB::transaction(function () use ($version) {
            LeavePolicyTypeRule::query()->where('policy_version_id', $version->id)->delete();
            $version->delete();
        });
        Audit::record('deleted', 'leave', 'leave_policy_version:'.$version->uuid, ['version' => $version->version]);
    }

    /** @return array{version:LeavePolicyVersion,closed:array<int,array<string,mixed>>,warnings:array<int,string>} */
    public function publish(LeavePolicyVersion $version, ?User $actor = null, bool $requireFuture = false): array
    {
        return DB::transaction(function () use ($version, $actor, $requireFuture) {
            LeavePolicy::query()->whereKey($version->policy_id)->lockForUpdate()->first();
            $version = LeavePolicyVersion::query()->whereKey($version->id)->firstOrFail();
            if ($version->status !== self::DRAFT) {
                throw ValidationException::withMessages(['version' => 'Only a draft version can be published.']);
            }
            $today = TenantClock::today();
            $this->assertPolicyRules($version->rules ?? []);
            $typeRules = $version->typeRules()->get();
            if ($typeRules->isEmpty()) {
                throw ValidationException::withMessages(['type_rules' => 'Add at least one leave type to this version before publishing.']);
            }
            foreach ($typeRules as $row) {
                $type = LeaveType::query()->find($row->leave_type_id);
                if (! $type || ! $type->active) {
                    throw ValidationException::withMessages(['type_rules' => 'Leave type #'.$row->leave_type_id.' is inactive or missing.']);
                }
                $this->assertTypeRules($type, $row->rules ?? []);
            }
            $this->assertCyclesExist($version->rules ?? [], $typeRules->pluck('rules')->all());
            $from = $version->effective_from;
            $to = $version->effective_to;
            $this->assertRange($from, $to);
            if ($requireFuture && $from <= $today) {
                throw ValidationException::withMessages(['effective_from' => 'A scheduled version must start after today ('.$today.').']);
            }
            if ($from < $today) {
                $this->assertNoHistoryRewrite($version->policy_id, $from);
            }
            $others = LeavePolicyVersion::query()->where('policy_id', $version->policy_id)->whereIn('status', self::LIVE)->where('id', '!=', $version->id)->orderBy('effective_from')->get();
            if ($others->contains(fn ($o) => $o->effective_from === $from)) {
                throw ValidationException::withMessages(['effective_from' => 'Another published version already starts on '.$from.'. Choose a different effective date.']);
            }
            $warnings = [];
            $next = $others->first(fn ($o) => $o->effective_from > $from);
            if ($next && ($to === null || $to >= $next->effective_from)) {
                if ($to !== null) {
                    throw ValidationException::withMessages(['effective_to' => 'This version would overlap version '.$next->version.' which starts on '.$next->effective_from.'. End it earlier or leave the end date empty.']);
                }
                $to = CarbonImmutable::parse($next->effective_from)->subDay()->toDateString();
            }
            $closed = [];
            foreach ($others as $other) {
                if ($other->effective_from < $from && ($other->effective_to === null || $other->effective_to >= $from)) {
                    $newEnd = CarbonImmutable::parse($from)->subDay()->toDateString();
                    $closed[] = ['version' => $other->version, 'previous_effective_to' => $other->effective_to, 'effective_to' => $newEnd];
                    $other->effective_to = $newEnd; // the single permitted mutation of a published version
                    $other->save();
                }
            }
            if ($to !== null && ! $next) {
                $warnings[] = 'No version of this policy is in effect after '.$to.'.';
            }
            $version->forceFill(['effective_to' => $to, 'status' => $from > $today ? self::SCHEDULED : self::ACTIVE, 'published_at' => now(), 'published_by' => $actor?->id,
                'checksum' => $this->checksumFor($version->rules ?? [], $typeRules)])->save();
            $this->syncStatuses($version->policy_id);
            Audit::record('published', 'leave', 'leave_policy_version:'.$version->uuid, ['effective_from' => $from, 'effective_to' => $to, 'closed' => $closed]);
            app(DomainEvents::class)->record('leave.policy.published', ['summary' => 'Leave policy version published', 'classification' => Classification::HR_CONFIDENTIAL, 'effective_date' => $from,
                'payload' => ['policy_version' => $version->uuid, 'policy' => $version->policy_id], 'subject' => ['leave_policy_versions', $version->id]]);

            return ['version' => $version->fresh('policy'), 'closed' => $closed, 'warnings' => $warnings];
        });
    }

    /** @return array{version:LeavePolicyVersion,warnings:array<int,string>} */
    public function retire(LeavePolicyVersion $version): array
    {
        return DB::transaction(function () use ($version) {
            LeavePolicy::query()->whereKey($version->policy_id)->lockForUpdate()->first();
            $version = LeavePolicyVersion::query()->whereKey($version->id)->firstOrFail();
            if (! in_array($version->status, self::LIVE, true)) {
                throw ValidationException::withMessages(['version' => 'Only a published version can be retired.']);
            }
            $today = TenantClock::today();
            $warnings = [];
            if ($version->effective_from > $today) { // never took effect: cancel it and give the earlier version its range back
                $previous = LeavePolicyVersion::query()->where('policy_id', $version->policy_id)->whereIn('status', array_merge(self::LIVE, [self::RETIRED]))
                    ->where('effective_to', CarbonImmutable::parse($version->effective_from)->subDay()->toDateString())->first();
                $oldEnd = $version->effective_to;
                $version->effective_to = CarbonImmutable::parse($version->effective_from)->subDay()->toDateString();
                if ($previous && $previous->status !== self::RETIRED) {
                    $previous->effective_to = $oldEnd;
                    $previous->save();
                    $warnings[] = 'Version '.$previous->version.' is in effect again for the dates this version would have covered.';
                }
            } elseif ($version->effective_to === null || $version->effective_to > $today) {
                $version->effective_to = $today;
                $warnings[] = 'Leave after '.$today.' has no policy from this version. Publish another version to avoid "no policy" gaps.';
            }
            $version->status = self::RETIRED;
            $version->save();
            $this->syncStatuses($version->policy_id);
            Audit::record('retired', 'leave', 'leave_policy_version:'.$version->uuid, ['effective_to' => $version->effective_to]);

            return ['version' => $version->fresh('policy'), 'warnings' => $warnings];
        });
    }

    /** Derive scheduled/active/retired from the effective range (idempotent). Never touches rules, type rules or checksum. */
    public function syncStatuses(int $policyId): void
    {
        $today = TenantClock::today();
        LeavePolicyVersion::query()->where('policy_id', $policyId)->whereIn('status', self::LIVE)->get()->each(function ($v) use ($today) {
            $status = $v->effective_to !== null && $v->effective_to < $today ? self::RETIRED : ($v->effective_from > $today ? self::SCHEDULED : self::ACTIVE);
            if ($status !== $v->status) {
                $v->status = $status;
                $v->save();
            }
        });
    }

    public function checksumFor(array $rules, iterable $typeRules): string
    {
        $map = [];
        foreach ($typeRules as $row) {
            $map[(string) LeaveType::query()->whereKey($row->leave_type_id)->value('code')] = $row->rules ?? [];
        }
        ksort($map);

        return Canonical::hash(['rules' => $rules, 'type_rules' => $map]);
    }

    public function integrityOk(LeavePolicyVersion $version): ?bool
    {
        return $version->checksum ? hash_equals($version->checksum, $this->checksumFor($version->rules ?? [], $version->typeRules()->get())) : null;
    }

    // ---- Assignments --------------------------------------------------------------------------

    public function createAssignment(array $data, ?User $actor = null): LeavePolicyAssignment
    {
        $row = $this->normalizeAssignment($data);
        $this->assertNoAmbiguity($row, null);

        return DB::transaction(function () use ($row, $actor) {
            $assignment = LeavePolicyAssignment::create($row + ['created_by' => $actor?->id]);
            Audit::record('created', 'leave', 'leave_policy_assignment:'.$assignment->uuid, $row);

            return $assignment;
        });
    }

    public function updateAssignment(LeavePolicyAssignment $assignment, array $data): LeavePolicyAssignment
    {
        $row = $this->normalizeAssignment(array_merge($assignment->only(['policy_id', 'target_type', 'target_id', 'effective_from', 'effective_to', 'priority', 'is_override', 'note']), $data));
        $this->assertNoAmbiguity($row, $assignment->id);
        $assignment->fill($row)->save();
        Audit::record('updated', 'leave', 'leave_policy_assignment:'.$assignment->uuid, $row);

        return $assignment;
    }

    public function deleteAssignment(LeavePolicyAssignment $assignment): void
    {
        Audit::record('deleted', 'leave', 'leave_policy_assignment:'.$assignment->uuid, $assignment->only(['policy_id', 'target_type', 'target_id']));
        $assignment->delete();
    }

    // ---- Helpers -------------------------------------------------------------------------------

    public function assertPolicyRules(array $rules): void
    {
        $errors = $this->validator->policy($rules);
        if ($errors) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($m, $k) => ['rules.'.$k => $m])->all());
        }
    }

    public function assertTypeRules(LeaveType $type, array $rules): void
    {
        $errors = $this->validator->type($rules);
        if ($errors) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($m, $k) => ['type_rules.'.$type->code.'.'.$k => $m])->all());
        }
    }

    /**
     * @param  array<int,array{type:string,rules:array}>  $input
     * @return array<int,array> leave_type_id => rules
     */
    public function resolveTypeRules(array $input): array
    {
        $out = [];
        foreach ($input as $i => $item) {
            $ref = (string) ($item['type'] ?? '');
            $type = LeaveType::query()->where('uuid', $ref)->orWhere('code', $ref)->first()
                ?? throw ValidationException::withMessages(["type_rules.$i.type" => 'We could not find that leave type.']);
            if (isset($out[$type->id])) {
                throw ValidationException::withMessages(["type_rules.$i.type" => 'Each leave type can appear once per version.']);
            }
            $rules = $item['rules'] ?? [];
            $this->assertTypeRules($type, $rules);
            $out[$type->id] = $rules;
        }

        return $out;
    }

    private function assertDraft(LeavePolicyVersion $version): void
    {
        if ($version->status !== self::DRAFT) {
            throw ValidationException::withMessages(['version' => 'Published versions cannot be edited. Create a new version from it instead, so history stays intact.']);
        }
    }

    private function insertVersion(LeavePolicy $policy, int $number, array $rules, string $from, ?string $to, ?string $note, ?User $actor): LeavePolicyVersion
    {
        $this->assertRange($from, $to);
        $version = LeavePolicyVersion::create(['policy_id' => $policy->id, 'version' => $number, 'status' => self::DRAFT, 'effective_from' => $from, 'effective_to' => $to,
            'rules' => $rules, 'change_note' => $note, 'created_by' => $actor?->id]);
        Audit::record('created', 'leave', 'leave_policy_version:'.$version->uuid, ['change_note' => $note, 'version' => $number]);

        return $version;
    }

    private function assertRange(?string $from, ?string $to): void
    {
        if (! $from) {
            throw ValidationException::withMessages(['effective_from' => 'Choose the date this policy version takes effect.']);
        }
        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be before the start date.']);
        }
    }

    /** Rule/type-rule `cycle` references must name an existing cycle. */
    private function assertCyclesExist(array $policyRules, array $typeRuleDocs): void
    {
        $codes = array_filter(array_merge([$policyRules['cycle'] ?? null], array_map(fn ($r) => $r['cycle'] ?? null, $typeRuleDocs)));
        foreach (array_unique($codes) as $code) {
            if (! LeaveCycle::query()->where('code', $code)->where('active', true)->exists()) {
                throw ValidationException::withMessages(['rules.cycle' => 'Cycle "'.$code.'" does not exist or is inactive.']);
            }
        }
    }

    /** A back-dated publish must not change how already-created accounts or requests were governed. */
    private function assertNoHistoryRewrite(int $policyId, string $from): void
    {
        $versionIds = LeavePolicyVersion::query()->where('policy_id', $policyId)->pluck('id');
        $accounts = DB::table('leave_accounts')->whereIn('policy_version_id', $versionIds)->where('period_end', '>=', $from)->exists();
        $requests = DB::table('leave_requests')->whereIn('policy_version_id', $versionIds)->where('end_date', '>=', $from)->exists();
        if ($accounts || $requests) {
            throw ValidationException::withMessages(['effective_from' => 'Leave accounts or requests already exist under this policy on or after '.$from.'. Publishing from that date would rewrite history; choose a later date.']);
        }
    }

    private function normalizeAssignment(array $data): array
    {
        [$type, $id] = Targets::normalize($data['target_type'] ?? null, $data['target_id'] ?? '*');
        $policy = LeavePolicy::query()->find($data['policy_id'] ?? 0) ?? throw ValidationException::withMessages(['policy' => 'Choose an existing policy.']);
        if (! $policy->active) {
            throw ValidationException::withMessages(['policy' => 'This policy is inactive. Activate it before assigning it.']);
        }
        $from = $data['effective_from'] ?? null;
        $to = $data['effective_to'] ?? null;
        $this->assertRange($from, $to);
        $priority = (int) ($data['priority'] ?? 0);
        if ($priority < -1000 || $priority > 1000) {
            throw ValidationException::withMessages(['priority' => 'Priority must be between -1000 and 1000.']);
        }

        return ['policy_id' => $policy->id, 'target_type' => $type, 'target_id' => $id, 'effective_from' => $from, 'effective_to' => $to, 'priority' => $priority,
            'is_override' => (bool) ($data['is_override'] ?? false), 'note' => $data['note'] ?? null];
    }

    /** Same target + overlapping dates + same override flag + same priority = nobody can tell which one wins. */
    private function assertNoAmbiguity(array $row, ?int $ignoreId): void
    {
        $clash = LeavePolicyAssignment::query()->where('target_type', $row['target_type'])->where('target_id', $row['target_id'])->where('priority', $row['priority'])
            ->where('is_override', $row['is_override'])->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $row['effective_from']))
            ->when($row['effective_to'], fn ($q) => $q->where('effective_from', '<=', $row['effective_to']))->first();
        if ($clash) {
            $name = LeavePolicy::query()->find($clash->policy_id)?->name ?? 'another policy';
            throw ValidationException::withMessages(['target_id' => 'Another assignment ('.$name.') already applies to '.Targets::label($row['target_type'], $row['target_id'])
                .' for these dates with the same priority, so it would be unclear which policy wins. Change the priority, mark one as an override, or end the other assignment first.']);
        }
    }

    public static function targetTypes(): array
    {
        return Specificity::types();
    }
}
