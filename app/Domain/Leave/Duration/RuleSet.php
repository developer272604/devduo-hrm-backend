<?php

namespace App\Domain\Leave\Duration;

use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Models\LeavePolicyTypeRule;
use App\Domain\Leave\Models\LeavePolicyVersion;
use App\Domain\Leave\Support\LeaveSettings;

/**
 * The rule document that governs one employee / leave type / date: the policy version resolved by workstream A's
 * LeavePolicyResolver (or an explicit version for previews and the policy sandbox). Reads are lazy so this module works
 * whether or not a policy is bound yet; with no policy the tenant settings alone apply.
 */
final class RuleSet
{
    /** @param array<string,mixed> $rules */
    public function __construct(public readonly ?int $versionId, public readonly array $rules, public readonly bool $granted, public readonly string $reason) {}

    public static function load(int $employeeId, int $leaveTypeId, string $date, ?int $versionId = null): self
    {
        if ($versionId) {
            $version = LeavePolicyVersion::query()->find($versionId);
            if (! $version) {
                return new self(null, [], false, 'The policy version was not found.');
            }
            $typeRule = LeavePolicyTypeRule::query()->where('policy_version_id', $version->id)->where('leave_type_id', $leaveTypeId)->first();
            if (! $typeRule) {
                return new self($version->id, [], false, 'This leave type is not part of the chosen policy version.');
            }
            $typeRules = is_array($typeRule->rules) ? $typeRule->rules : (json_decode((string) $typeRule->rules, true) ?: []);

            return new self($version->id, array_replace_recursive($version->rules ?? [], $typeRules), true, 'Evaluated against policy version '.$version->version.'.');
        }
        if (! app()->bound(LeavePolicyResolver::class)) {
            return new self(null, [], true, 'No leave policy is configured; workspace defaults apply.');
        }
        $resolved = app(LeavePolicyResolver::class)->rulesFor($employeeId, $leaveTypeId, $date);
        if ($resolved === null) {
            return new self(null, [], false, 'This leave type is not granted to the employee by their leave policy.');
        }

        return new self($resolved['version_id'] ?? null, $resolved['rules'] ?? [], true, $resolved['reason'] ?? 'Resolved from the employee\'s leave policy.');
    }

    /** First value found at limits.<key>, <key> (rule document) or the tenant settings path. */
    public function get(string $key, ?string $settingsPath = null, mixed $default = null): mixed
    {
        foreach (['limits.'.$key, $key] as $path) {
            $value = data_get($this->rules, $path);
            if ($value !== null) {
                return $value;
            }
        }

        return $settingsPath ? LeaveSettings::get($settingsPath, $default) : $default;
    }

    public function sandwich(): array
    {
        $s = $this->rules['sandwich'] ?? [];

        return ['enabled' => (bool) ($s['enabled'] ?? false), 'apply_to' => (array) ($s['apply_to'] ?? []), 'count_holidays' => (bool) ($s['count_holidays'] ?? true), 'count_weekly_offs' => (bool) ($s['count_weekly_offs'] ?? true)];
    }
}
