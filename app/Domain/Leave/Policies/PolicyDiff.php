<?php

namespace App\Domain\Leave\Policies;

use App\Domain\Leave\Models\LeavePolicyVersion;
use App\Domain\Leave\Models\LeaveType;

/**
 * Policy Difference Viewer: compares two versions (of one policy or of two policies) leaf by leaf. Output is plain data:
 * every change names the section (policy-wide or a leave type code), the dotted path, and the before/after value.
 */
final class PolicyDiff
{
    /** @return array<string,mixed> */
    public function compare(LeavePolicyVersion $from, LeavePolicyVersion $to): array
    {
        $changes = [];
        $this->diffSection($changes, 'policy', 'Policy-wide', (array) $from->rules, (array) $to->rules);

        $a = $this->typeRuleMap($from);
        $b = $this->typeRuleMap($to);
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $code) {
            $label = $b[$code]['name'] ?? $a[$code]['name'];
            if (! isset($a[$code])) {
                $changes[] = ['section' => $code, 'section_label' => $label, 'path' => '*', 'change' => 'type_added', 'before' => null, 'after' => $b[$code]['rules']];

                continue;
            }
            if (! isset($b[$code])) {
                $changes[] = ['section' => $code, 'section_label' => $label, 'path' => '*', 'change' => 'type_removed', 'before' => $a[$code]['rules'], 'after' => null];

                continue;
            }
            $this->diffSection($changes, $code, $label, $a[$code]['rules'], $b[$code]['rules']);
        }
        $meta = [];
        foreach (['effective_from', 'effective_to', 'status'] as $field) {
            if ($from->{$field} !== $to->{$field}) {
                $meta[] = ['field' => $field, 'before' => $from->{$field}, 'after' => $to->{$field}];
            }
        }
        $counts = array_count_values(array_column($changes, 'change'));

        return ['from' => $this->describe($from), 'to' => $this->describe($to), 'meta_changes' => $meta, 'changes' => $changes,
            'summary' => ['total' => count($changes), 'added' => ($counts['added'] ?? 0) + ($counts['type_added'] ?? 0), 'removed' => ($counts['removed'] ?? 0) + ($counts['type_removed'] ?? 0), 'changed' => $counts['changed'] ?? 0],
            'identical' => ! $changes && ! $meta];
    }

    private function describe(LeavePolicyVersion $v): array
    {
        $policy = $v->relationLoaded('policy') ? $v->policy : $v->policy()->first();

        return ['policy' => $policy?->uuid, 'policy_name' => $policy?->name, 'version' => $v->version, 'status' => $v->status, 'effective_from' => $v->effective_from, 'effective_to' => $v->effective_to, 'checksum' => $v->checksum];
    }

    /** @return array<string,array{name:string,rules:array}> */
    private function typeRuleMap(LeavePolicyVersion $v): array
    {
        $out = [];
        foreach ($v->typeRules()->get() as $row) {
            $type = LeaveType::query()->find($row->leave_type_id);
            if ($type) {
                $out[$type->code] = ['name' => $type->name, 'rules' => (array) $row->rules];
            }
        }

        return $out;
    }

    private function diffSection(array &$changes, string $section, string $label, array $before, array $after): void
    {
        $x = $this->flatten($before);
        $y = $this->flatten($after);
        foreach (array_unique(array_merge(array_keys($x), array_keys($y))) as $path) {
            $has = array_key_exists($path, $x);
            $had = array_key_exists($path, $y);
            if ($has && $had) {
                if ($this->same($x[$path], $y[$path])) {
                    continue;
                }
                $kind = 'changed';
            } else {
                $kind = $has ? 'removed' : 'added';
            }
            $changes[] = ['section' => $section, 'section_label' => $label, 'path' => $path, 'change' => $kind, 'before' => $x[$path] ?? null, 'after' => $y[$path] ?? null];
        }
    }

    private function same(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b) && ! is_bool($a) && ! is_bool($b)) {
            return bccomp(number_format((float) $a, 4, '.', ''), number_format((float) $b, 4, '.', ''), 4) === 0;
        }

        return $a === $b;
    }

    /** Maps flatten to dotted leaves; lists are kept whole (order matters, e.g. approval steps). */
    private function flatten(array $rules, string $prefix = ''): array
    {
        $out = [];
        foreach ($rules as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $out += $this->flatten($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }
}
