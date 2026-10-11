<?php

namespace App\Domain\Attendance\Policies;

use App\Domain\Attendance\Models\AttendancePolicy;
use App\Domain\Attendance\Models\AttendancePolicyAssignment;
use App\Domain\Attendance\Models\AttendancePolicyVersion;
use App\Domain\Attendance\Scheduling\Specificity;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Attendance\Support\PolicyRules;
use App\Domain\Audit\Audit;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Policy administration: versions are effective-dated and IMMUTABLE once published (an edit is a clone into a new draft
 * with a change note). Publishing closes the previously published version the day before the new one takes effect, in one
 * transaction, so two published versions of one policy never overlap.
 */
final class PolicyService
{
    private const ORG_TABLES = ['team' => 'teams', 'department' => 'departments', 'business_unit' => 'business_units', 'location' => 'locations',
        'employee_type' => 'employee_types', 'legal_entity' => 'legal_entities', 'employee_group' => 'employee_groups'];

    // ---- Policies ----------------------------------------------------------------------------

    public function create(array $data, ?User $actor = null): AttendancePolicy
    {
        $rules = $data['rules'] ?? [];
        $this->assertRules($rules);

        return DB::transaction(function () use ($data, $rules, $actor) {
            $policy = AttendancePolicy::create(['name' => $data['name'], 'code' => $data['code'], 'description' => $data['description'] ?? null,
                'country' => isset($data['country']) ? strtoupper($data['country']) : null, 'active' => $data['active'] ?? true]);
            $this->insertVersion($policy, 1, $rules, $data['effective_from'] ?? TenantClock::today(), $data['effective_to'] ?? null, $data['change_note'] ?? 'Initial version', $actor);
            Audit::record('created', 'attendance', 'attendance_policy:'.$policy->uuid, ['code' => $policy->code]);

            return $policy;
        });
    }

    public function update(AttendancePolicy $policy, array $data): AttendancePolicy
    {
        $policy->fill(array_intersect_key($data, array_flip(['name', 'description', 'country', 'active'])));
        if (isset($data['country'])) {
            $policy->country = strtoupper($data['country']);
        }
        $policy->save();
        Audit::record('updated', 'attendance', 'attendance_policy:'.$policy->uuid, array_keys($data));

        return $policy;
    }

    // ---- Versions ----------------------------------------------------------------------------

    /** Clone (or create) a draft version. Rules replace the base when given; otherwise the base rules are copied. */
    public function newVersion(AttendancePolicy $policy, array $data, ?User $actor = null): AttendancePolicyVersion
    {
        if (blank($data['change_note'] ?? null)) {
            throw ValidationException::withMessages(['change_note' => 'Describe what changed so the history stays understandable.']);
        }

        return DB::transaction(function () use ($policy, $data, $actor) {
            AttendancePolicy::query()->whereKey($policy->id)->lockForUpdate()->first();
            $base = null;
            if (isset($data['base_version'])) {
                $base = AttendancePolicyVersion::query()->where('policy_id', $policy->id)->where('version', (int) $data['base_version'])->first()
                    ?? throw ValidationException::withMessages(['base_version' => 'That version does not exist.']);
            } else {
                $base = AttendancePolicyVersion::query()->where('policy_id', $policy->id)->orderByDesc('version')->first();
            }
            $rules = $data['rules'] ?? ($base?->rules ?? []);
            $this->assertRules($rules);
            $next = (int) AttendancePolicyVersion::query()->where('policy_id', $policy->id)->max('version') + 1;

            return $this->insertVersion($policy, $next, $rules, $data['effective_from'] ?? TenantClock::today(), $data['effective_to'] ?? null, $data['change_note'], $actor);
        });
    }

    public function updateDraft(AttendancePolicyVersion $version, array $data): AttendancePolicyVersion
    {
        if ($version->status !== AttendancePolicyVersion::DRAFT) {
            throw ValidationException::withMessages(['version' => 'Published versions cannot be edited. Create a new version from it instead, so history stays intact.']);
        }
        if (array_key_exists('rules', $data)) {
            $this->assertRules($data['rules']);
            $version->rules = $data['rules'];
            $version->rules_hash = $this->hash($data['rules']);
        }
        foreach (['effective_from', 'effective_to', 'change_note'] as $field) {
            if (array_key_exists($field, $data)) {
                $version->{$field} = $data[$field];
            }
        }
        $this->assertRange($version->effective_from, $version->effective_to);
        $version->save();
        Audit::record('updated', 'attendance', 'attendance_policy_version:'.$version->policy_id.'.'.$version->version, array_keys($data));

        return $version;
    }

    /** @return array{version:AttendancePolicyVersion,closed:array<int,array<string,mixed>>,warnings:array<int,string>} */
    public function publish(AttendancePolicyVersion $version, ?User $actor = null): array
    {
        return DB::transaction(function () use ($version, $actor) {
            AttendancePolicy::query()->whereKey($version->policy_id)->lockForUpdate()->first();
            $version = AttendancePolicyVersion::query()->whereKey($version->id)->firstOrFail();
            if ($version->status !== AttendancePolicyVersion::DRAFT) {
                throw ValidationException::withMessages(['version' => 'Only a draft version can be published.']);
            }
            $this->assertRules($version->rules ?? []);
            $from = $version->effective_from;
            $to = $version->effective_to;
            $this->assertRange($from, $to);
            $others = AttendancePolicyVersion::query()->where('policy_id', $version->policy_id)->where('status', AttendancePolicyVersion::PUBLISHED)->where('id', '!=', $version->id)->orderBy('effective_from')->get();
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
            $version->forceFill(['status' => AttendancePolicyVersion::PUBLISHED, 'effective_to' => $to, 'published_at' => Utc::str(), 'published_by' => $actor?->id, 'rules_hash' => $this->hash($version->rules ?? [])])->save();
            Audit::record('published', 'attendance', 'attendance_policy_version:'.$version->policy_id.'.'.$version->version, ['effective_from' => $from, 'effective_to' => $to, 'closed' => $closed]);

            return ['version' => $version->fresh('policy'), 'closed' => $closed, 'warnings' => $warnings];
        });
    }

    /** @return array{version:AttendancePolicyVersion,warnings:array<int,string>} */
    public function retire(AttendancePolicyVersion $version): array
    {
        return DB::transaction(function () use ($version) {
            AttendancePolicy::query()->whereKey($version->policy_id)->lockForUpdate()->first();
            $version = AttendancePolicyVersion::query()->whereKey($version->id)->firstOrFail();
            if ($version->status !== AttendancePolicyVersion::PUBLISHED) {
                throw ValidationException::withMessages(['version' => 'Only a published version can be retired.']);
            }
            $today = TenantClock::today();
            $warnings = [];
            if ($version->effective_from > $today) { // never took effect: cancel it and give the earlier version its range back
                $previous = AttendancePolicyVersion::query()->where('policy_id', $version->policy_id)->where('status', AttendancePolicyVersion::PUBLISHED)
                    ->where('effective_to', CarbonImmutable::parse($version->effective_from)->subDay()->toDateString())->first();
                $oldEnd = $version->effective_to;
                $version->effective_to = CarbonImmutable::parse($version->effective_from)->subDay()->toDateString();
                if ($previous) {
                    $previous->effective_to = $oldEnd;
                    $previous->save();
                    $warnings[] = 'Version '.$previous->version.' is in effect again for the dates this version would have covered.';
                }
            } else {
                if ($version->effective_to === null || $version->effective_to > $today) {
                    $version->effective_to = $today;
                    $warnings[] = 'Attendance after '.$today.' has no policy from this version. Publish another version to avoid "no policy" exceptions.';
                }
            }
            $version->status = AttendancePolicyVersion::RETIRED;
            $version->save();
            Audit::record('retired', 'attendance', 'attendance_policy_version:'.$version->policy_id.'.'.$version->version, ['effective_to' => $version->effective_to]);

            return ['version' => $version->fresh('policy'), 'warnings' => $warnings];
        });
    }

    // ---- Assignments --------------------------------------------------------------------------

    public function createAssignment(array $data, ?User $actor = null): AttendancePolicyAssignment
    {
        $row = $this->normalizeAssignment($data);
        $this->assertNoAmbiguity($row, null);

        return DB::transaction(function () use ($row, $actor) {
            $assignment = AttendancePolicyAssignment::create($row + ['created_by' => $actor?->id]);
            Audit::record('created', 'attendance', 'attendance_policy_assignment:'.$assignment->id, $row);

            return $assignment;
        });
    }

    public function updateAssignment(AttendancePolicyAssignment $assignment, array $data): AttendancePolicyAssignment
    {
        $merged = array_merge($assignment->only(['policy_id', 'target_type', 'target_id', 'effective_from', 'effective_to', 'priority', 'is_override', 'note']), $data);
        $row = $this->normalizeAssignment($merged);
        $this->assertNoAmbiguity($row, $assignment->id);
        $assignment->fill($row)->save();
        Audit::record('updated', 'attendance', 'attendance_policy_assignment:'.$assignment->id, $row);

        return $assignment;
    }

    public function deleteAssignment(AttendancePolicyAssignment $assignment): void
    {
        Audit::record('deleted', 'attendance', 'attendance_policy_assignment:'.$assignment->id, $assignment->only(['policy_id', 'target_type', 'target_id']));
        $assignment->delete();
    }

    public function targetLabel(string $type, string $id): string
    {
        if ($type === 'tenant') {
            return 'the whole company';
        }
        if ($type === 'country') {
            return 'country '.$id;
        }
        if ($type === 'employee') {
            $name = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)
                ->where('employees.id', $id)->value('people.display_name');

            return $name ?? 'employee '.$id;
        }
        $table = self::ORG_TABLES[$type] ?? null;
        $name = $table ? DB::table($table)->where('id', $id)->value('name') : null;

        return ($name ?? '#'.$id).' ('.Specificity::label($type).')';
    }

    // ---- Helpers -------------------------------------------------------------------------------

    /** sha256 of canonical JSON: keys of maps sorted recursively, lists keep their order. */
    public function hash(array $rules): string
    {
        return \App\Domain\Attendance\Processing\Canonical::hash($rules);
    }

    private function insertVersion(AttendancePolicy $policy, int $number, array $rules, string $from, ?string $to, ?string $note, ?User $actor): AttendancePolicyVersion
    {
        $this->assertRange($from, $to);
        $version = AttendancePolicyVersion::create(['policy_id' => $policy->id, 'version' => $number, 'status' => AttendancePolicyVersion::DRAFT, 'effective_from' => $from, 'effective_to' => $to,
            'rules' => $rules, 'rules_hash' => $this->hash($rules), 'change_note' => $note, 'created_by' => $actor?->id]);
        Audit::record('created', 'attendance', 'attendance_policy_version:'.$policy->id.'.'.$number, ['change_note' => $note]);

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

    public function assertRules(array $rules): void
    {
        $unknown = array_diff(array_keys($rules), array_keys(PolicyRules::DEFAULTS));
        $messages = [];
        foreach ($unknown as $key) {
            $messages['rules.'.$key] = 'Unknown rule section "'.$key.'".';
        }
        foreach (PolicyRules::validate($rules) as $path => $message) {
            $messages['rules.'.$path] = $message;
        }
        if ($messages) {
            throw ValidationException::withMessages($messages);
        }
    }

    private function normalizeAssignment(array $data): array
    {
        $type = $data['target_type'] ?? null;
        if (! in_array($type, Specificity::types(), true)) {
            throw ValidationException::withMessages(['target_type' => 'Choose who the policy applies to: '.implode(', ', Specificity::types()).'.']);
        }
        $policy = AttendancePolicy::query()->find($data['policy_id'] ?? 0) ?? throw ValidationException::withMessages(['policy_id' => 'Choose an existing policy.']);
        if (! $policy->active) {
            throw ValidationException::withMessages(['policy_id' => 'This policy is inactive. Activate it before assigning it.']);
        }
        $id = (string) ($data['target_id'] ?? '*');
        if ($type === 'tenant') {
            $id = '*';
        } elseif ($type === 'country') {
            $id = strtoupper($id);
            if (! preg_match('/^[A-Z]{2}$/', $id)) {
                throw ValidationException::withMessages(['target_id' => 'Use a two-letter country code such as IN.']);
            }
        } elseif ($type === 'employee' && ! ctype_digit($id)) {
            $id = (string) (DB::table('employees')->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('uuid', $id)->value('id') ?? throw ValidationException::withMessages(['target_id' => 'We could not find that employee.']));
        }
        if ($type === 'employee') {
            if (! DB::table('employees')->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('id', $id)->exists()) {
                throw ValidationException::withMessages(['target_id' => 'We could not find that employee.']);
            }
        } elseif (isset(self::ORG_TABLES[$type]) && ! DB::table(self::ORG_TABLES[$type])->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->where('id', $id)->exists()) {
            throw ValidationException::withMessages(['target_id' => 'We could not find that '.Specificity::label($type).'.']);
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
        $clash = AttendancePolicyAssignment::query()->where('target_type', $row['target_type'])->where('target_id', $row['target_id'])->where('priority', $row['priority'])
            ->where('is_override', $row['is_override'])->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $row['effective_from']))
            ->when($row['effective_to'], fn ($q) => $q->where('effective_from', '<=', $row['effective_to']))->first();
        if ($clash) {
            $name = AttendancePolicy::query()->find($clash->policy_id)?->name ?? 'another policy';
            throw ValidationException::withMessages(['target_id' => 'Another assignment ('.$name.') already applies to '.$this->targetLabel($row['target_type'], $row['target_id'])
                .' for these dates with the same priority, so it would be unclear which policy wins. Change the priority, mark one as an override, or end the other assignment first.']);
        }
    }
}
