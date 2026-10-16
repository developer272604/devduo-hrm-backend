<?php

namespace App\Domain\Intelligence\Planning;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Transparent workforce what-if model. Every input is an assumption the user states; nothing is predicted, learned or read from
 * confidential payroll data. Expected-value arithmetic per month, run for a low / base / high attrition range and compared across
 * up to four alternative hiring plans.
 *
 *  - Attrition: an annual % is converted to a monthly rate and applied to every pool (existing staff and each hiring wave).
 *  - A hiring wave starts recruiting in `month`, its people join `lead_time_months` later and cost `monthly_cost` each from the join month.
 *  - Ramp: a new joiner contributes (age + 1) / (ramp + 1) of a full person, so effective capacity trails headcount.
 *  - Backfill: when enabled, expected leavers are replaced after `backfill_lead_months` at the average cost.
 */
final class WorkforcePlanner
{
    public const MAX_MONTHS = 36;

    public const MAX_ALTERNATIVES = 4;

    public const MAX_WAVES = 12;

    public static function validate(array $p): array
    {
        $v = Validator::make($p, [
            'months' => 'required|integer|min:1|max:'.self::MAX_MONTHS,
            'currency' => 'nullable|string|size:3',
            'baseline_headcount' => 'nullable|integer|min:0|max:1000000',
            'avg_monthly_cost' => 'nullable|numeric|min:0|max:1000000000',
            'annual_attrition_pct' => 'required|numeric|min:0|max:100',
            'attrition_low_pct' => 'nullable|numeric|min:0|max:100',
            'attrition_high_pct' => 'nullable|numeric|min:0|max:100',
            'monthly_budget' => 'nullable|numeric|min:0|max:1000000000000',
            'alternatives' => 'required|array|min:1|max:'.self::MAX_ALTERNATIVES,
            'alternatives.*.name' => 'required|string|max:80',
            'alternatives.*.backfill' => 'nullable|boolean',
            'alternatives.*.backfill_lead_months' => 'nullable|integer|min:0|max:12',
            'alternatives.*.waves' => 'nullable|array|max:'.self::MAX_WAVES,
            'alternatives.*.waves.*.month' => 'required|integer|min:1|max:'.self::MAX_MONTHS,
            'alternatives.*.waves.*.count' => 'required|integer|min:1|max:100000',
            'alternatives.*.waves.*.monthly_cost' => 'nullable|numeric|min:0|max:1000000000',
            'alternatives.*.waves.*.lead_time_months' => 'nullable|integer|min:0|max:12',
            'alternatives.*.waves.*.ramp_months' => 'nullable|integer|min:0|max:12',
        ]);
        $v->after(function ($v) use ($p) {
            $base = (float) ($p['annual_attrition_pct'] ?? 0);
            $low = isset($p['attrition_low_pct']) ? (float) $p['attrition_low_pct'] : null;
            $high = isset($p['attrition_high_pct']) ? (float) $p['attrition_high_pct'] : null;
            if (($low !== null && $low > $base) || ($high !== null && $high < $base)) {
                $v->errors()->add('attrition_low_pct', 'Low must not exceed the base rate and high must not be below it.');
            }
        });
        if ($v->fails()) {
            throw new ValidationException($v);
        }

        return $v->validated();
    }

    /** @return array<string,mixed> */
    public static function plan(array $p, int $scopedHeadcount): array
    {
        $p = self::validate($p);
        $months = (int) $p['months'];
        $base = (float) $p['annual_attrition_pct'];
        $cases = ['low' => (float) ($p['attrition_low_pct'] ?? $base * 0.5), 'base' => $base, 'high' => (float) ($p['attrition_high_pct'] ?? min(100, $base * 1.5))];
        $start = (int) ($p['baseline_headcount'] ?? $scopedHeadcount);
        $avg = isset($p['avg_monthly_cost']) ? (float) $p['avg_monthly_cost'] : null;
        $budget = isset($p['monthly_budget']) ? (float) $p['monthly_budget'] : null;
        $out = [];
        foreach ($p['alternatives'] as $alt) {
            $row = ['name' => $alt['name'], 'cases' => []];
            foreach ($cases as $label => $annual) {
                $row['cases'][$label] = self::run($alt, $months, $start, $avg, $annual, $budget);
            }
            $out[] = $row;
        }
        $first = $out[0]['cases']['base'];
        foreach ($out as $i => $row) {
            $b = $row['cases']['base'];
            $out[$i]['vs_first'] = $i === 0 ? null : ['headcount' => round($b['end_headcount'] - $first['end_headcount'], 1), 'effective_fte' => round($b['end_effective_fte'] - $first['end_effective_fte'], 1),
                'total_cost' => $b['total_cost'] !== null && $first['total_cost'] !== null ? round($b['total_cost'] - $first['total_cost'], 2) : null];
        }

        return ['currency' => $p['currency'] ?? null, 'baseline_headcount' => $start, 'baseline_source' => isset($p['baseline_headcount']) ? 'entered by you' : 'working employees inside your data scope',
            'assumptions' => ['months' => $months, 'annual_attrition_pct' => $cases, 'avg_monthly_cost' => $avg, 'monthly_budget' => $budget], 'alternatives' => $out,
            'method' => 'Expected-value arithmetic per month using only the assumptions above. Attrition is applied to every group, hires join after their lead time, and capacity ramps up gradually. Low and high show the attrition range you chose.',
            'limitations' => 'This is a what-if model, not a forecast. Attrition, lead times and costs are your assumptions. Confidential payroll data is not read; enter an average monthly cost yourself. Results are expected values, so real months will vary.'];
    }

    private static function run(array $alt, int $months, int $start, ?float $avg, float $annualPct, ?float $budget): array
    {
        $rate = 1 - (1 - $annualPct / 100) ** (1 / 12);
        $existing = (float) $start;
        $waves = [];
        foreach ($alt['waves'] ?? [] as $w) {
            $waves[] = ['join' => (int) $w['month'] + (int) ($w['lead_time_months'] ?? 2), 'count' => (float) $w['count'], 'cost' => isset($w['monthly_cost']) ? (float) $w['monthly_cost'] : $avg, 'ramp' => (int) ($w['ramp_months'] ?? 0), 'live' => 0.0, 'joined' => false];
        }
        $backfill = ! empty($alt['backfill']);
        $bfLead = (int) ($alt['backfill_lead_months'] ?? 2);
        $pending = []; // month => expected backfill hires joining that month
        $backPool = 0.0;
        $series = [];
        $cumulative = 0.0;
        $costKnown = $avg !== null; // existing staff need a cost, otherwise a total would silently understate
        $overBudget = 0;
        $firstOver = null;
        $exitsTotal = 0.0;
        for ($m = 1; $m <= $months; $m++) {
            $exits = $existing * $rate + $backPool * $rate;
            $existing -= $existing * $rate;
            $backPool -= $backPool * $rate;
            foreach ($waves as &$w) {
                if ($w['joined']) {
                    $exits += $w['live'] * $rate;
                    $w['live'] -= $w['live'] * $rate;
                }
                if (! $w['joined'] && $m === $w['join']) {
                    $w['joined'] = true;
                    $w['live'] = $w['count'];
                }
            }
            unset($w);
            if ($backfill) {
                $pending[$m + $bfLead] = ($pending[$m + $bfLead] ?? 0) + $exits;
                $backPool += $pending[$m] ?? 0;
            }
            $exitsTotal += $exits;
            $headcount = $existing + $backPool + array_sum(array_column($waves, 'live'));
            $effective = $existing + $backPool;
            $cost = $avg !== null ? ($existing + $backPool) * $avg : 0.0;
            foreach ($waves as $w) {
                if ($w['joined']) {
                    $age = $m - $w['join'];
                    $effective += $w['live'] * min(1, ($age + 1) / ($w['ramp'] + 1));
                    $cost += $w['live'] * ($w['cost'] ?? 0);
                }
            }
            $cumulative += $cost;
            $over = $budget !== null && $costKnown && $cost > $budget;
            $overBudget += $over ? 1 : 0;
            $firstOver ??= $over ? $m : null;
            $series[] = ['month' => $m, 'headcount' => round($headcount, 1), 'effective_fte' => round($effective, 1), 'monthly_cost' => $costKnown ? round($cost, 2) : null, 'cumulative_cost' => $costKnown ? round($cumulative, 2) : null];
        }
        $last = end($series);

        return ['annual_attrition_pct' => round($annualPct, 2), 'end_headcount' => $last['headcount'], 'end_effective_fte' => $last['effective_fte'], 'expected_leavers' => round($exitsTotal, 1),
            'total_cost' => $costKnown ? round($cumulative, 2) : null, 'months_over_budget' => $budget !== null && $costKnown ? $overBudget : null, 'first_month_over_budget' => $firstOver, 'series' => $series];
    }
}
