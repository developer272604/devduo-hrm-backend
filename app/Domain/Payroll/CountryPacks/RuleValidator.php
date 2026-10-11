<?php

namespace App\Domain\Payroll\CountryPacks;

use App\Domain\Payroll\Contracts\FormulaEvaluator;
use App\Domain\Payroll\Support\Decimal;

/**
 * Static validation of declarative rules (calculation_rules). Rules are DATA: nothing here knows a country.
 *
 * calculation_rules = { concepts: [ concept ], overtime_multipliers?: {key: decimal string} }
 * concept = { id, kind: tax|employee_contribution|employer_contribution|deduction|benefit|informational, type: rate|slab|flat|formula,
 *             component_code?, base (amounts key, default "gross"), when?: [{base, op: gte|gt|lte|lt|eq, value}],
 *             rate? (percent), slabs? [{up_to|null, rate}] (annual, progressive), annualize? (default true for slab),
 *             amount? (flat), formula? (variables = amounts keys), wage_cap?, wage_floor?, min?, max?, rounding?: {mode, scale} }
 * All numbers are decimal STRINGS (JSON integers are accepted and normalised; floats are refused).
 */
final class RuleValidator
{
    public const KINDS = ['tax', 'employee_contribution', 'employer_contribution', 'deduction', 'benefit', 'informational'];

    public const TYPES = ['rate', 'slab', 'flat', 'formula'];

    public function __construct(private FormulaEvaluator $formulas) {}

    /** @return array<int,string> user-facing problems */
    public function problems(?array $rules): array
    {
        $p = [];
        if (! is_array($rules) || ! isset($rules['concepts']) || ! is_array($rules['concepts']) || $rules['concepts'] === []) return ['calculation_rules.concepts must list at least one rule concept.'];
        $ids = [];
        foreach ($rules['concepts'] as $i => $c) {
            $at = "concepts[$i]";
            if (! is_array($c)) { $p[] = "$at must be an object."; continue; }
            if (! preg_match('/^[a-z][a-z0-9_]{0,59}$/D', (string) ($c['id'] ?? ''))) $p[] = "$at.id must be lowercase letters, digits or underscores.";
            elseif (isset($ids[$c['id']])) $p[] = "$at.id \"{$c['id']}\" is duplicated."; else $ids[$c['id']] = true;
            if (! in_array($c['kind'] ?? null, self::KINDS, true)) $p[] = "$at.kind must be one of ".implode(', ', self::KINDS).'.';
            $type = $c['type'] ?? null;
            if (! in_array($type, self::TYPES, true)) { $p[] = "$at.type must be one of ".implode(', ', self::TYPES).'.'; continue; }
            foreach (['wage_cap', 'wage_floor', 'min', 'max', 'amount'] as $f) if (isset($c[$f]) && ! $this->num($c[$f])) $p[] = "$at.$f must be a decimal string.";
            if (isset($c['wage_cap'], $c['wage_floor']) && $this->num($c['wage_cap']) && $this->num($c['wage_floor']) && Decimal::cmp($c['wage_floor'], $c['wage_cap']) > 0) $p[] = "$at.wage_floor exceeds wage_cap.";
            foreach ($c['when'] ?? [] as $j => $w) if (! is_array($w) || ! in_array($w['op'] ?? null, ['gte', 'gt', 'lte', 'lt', 'eq'], true) || ! $this->num($w['value'] ?? null) || empty($w['base'])) $p[] = "$at.when[$j] needs base, op (gte|gt|lte|lt|eq) and a decimal value.";
            if ($type === 'rate' && ! $this->pct($c['rate'] ?? null)) $p[] = "$at.rate must be a percentage between 0 and 100.";
            if ($type === 'flat' && ! isset($c['amount'])) $p[] = "$at.amount is required for flat rules.";
            if ($type === 'formula') {
                if (empty($c['formula']) || ! is_string($c['formula'])) $p[] = "$at.formula is required.";
                else foreach ($this->formulas->validate($c['formula'], array_map('strtoupper', array_merge(['gross', 'basic', 'taxable_gross'], $rules['variables'] ?? [], $c['variables'] ?? []))) as $m) { $p[] = "$at.formula: $m"; break; }
            }
            if ($type === 'slab') {
                $s = $c['slabs'] ?? null; $prev = null;
                if (! is_array($s) || $s === []) { $p[] = "$at.slabs must list at least one slab."; continue; }
                foreach ($s as $k => $slab) {
                    if (! $this->pct($slab['rate'] ?? null)) $p[] = "$at.slabs[$k].rate must be a percentage between 0 and 100.";
                    $up = $slab['up_to'] ?? null;
                    if ($up === null) { if ($k !== array_key_last($s)) $p[] = "$at.slabs[$k]: only the last slab may be unbounded (up_to null)."; continue; }
                    if (! $this->num($up)) { $p[] = "$at.slabs[$k].up_to must be a decimal string or null."; continue; }
                    if ($prev !== null && Decimal::cmp($up, $prev) <= 0) $p[] = "$at.slabs[$k].up_to must be greater than the previous slab.";
                    $prev = $up;
                }
                if ((end($s)['up_to'] ?? null) !== null) $p[] = "$at.slabs: the last slab must be unbounded (up_to null).";
            }
            if (isset($c['rounding']) && (! \App\Domain\Payroll\Support\Rounding::valid((string) ($c['rounding']['mode'] ?? '')) || ! isset($c['rounding']['scale']))) $p[] = "$at.rounding needs a valid mode and scale.";
        }
        foreach ($rules['overtime_multipliers'] ?? [] as $k => $v) if (! $this->num($v)) $p[] = "overtime_multipliers.$k must be a decimal string.";

        return $p;
    }

    /** Normalises integers to decimal strings; rejects floats. */
    public function normalize(mixed $v): mixed
    {
        if (is_array($v)) return array_map(fn ($x) => $this->normalize($x), $v);
        if (is_int($v)) return (string) $v;
        if (is_float($v)) throw new \InvalidArgumentException('Floating point numbers are not allowed in pack rules; use decimal strings.');

        return $v;
    }

    private function num(mixed $v): bool { return is_string($v) && preg_match('/^-?\d{1,14}(?:\.\d{1,6})?$/D', $v) === 1; }

    private function pct(mixed $v): bool { return $this->num($v) && Decimal::cmp($v, 0) >= 0 && Decimal::cmp($v, 100) <= 0; }
}
