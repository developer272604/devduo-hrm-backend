<?php

namespace App\Domain\Leave\Simulation;

use App\Domain\Leave\Support\Units;
use Carbon\CarbonImmutable;

/**
 * Pure evaluator of the per-type rule document conventions (docs/api/leave-foundation.md "Rule document"), used ONLY by the
 * read-only sandbox/simulator when the real EntitlementEngine cannot evaluate a draft. No country law lives here: every number
 * comes from the rule document passed in.
 *
 *   entitlement {basis: annual|monthly, amount, tenure_bands[{from_days|from_months|min_days|min_months|min_years, amount}], proration{enabled, rounding_step}}
 *   carry_forward {mode: none|full|capped|percentage, cap, percent}      limits {max_balance}
 */
final class RuleEvaluator
{
    /** @return array{units:string,lines:array<int,array<string,mixed>>} */
    public static function entitlement(array $rules, string $joinedOn, string $periodStart, string $periodEnd): array
    {
        $e = $rules['entitlement'] ?? [];
        $lines = [];
        if (! isset($e['amount'])) {
            return ['units' => '0.0000', 'lines' => [['label' => 'No entitlement amount in the rules', 'units' => '0.0000']]];
        }
        $amount = Units::normalize($e['amount']);
        $basis = $e['basis'] ?? 'annual';
        $monthsInPeriod = max(1, (int) round(CarbonImmutable::parse($periodStart)->diffInMonths(CarbonImmutable::parse($periodEnd)->addDay())));
        $units = $basis === 'monthly' ? Units::mul($amount, (string) $monthsInPeriod) : $amount;
        $lines[] = ['label' => $basis === 'monthly' ? $amount.' per month x '.$monthsInPeriod.' months' : 'Base entitlement', 'units' => $units];

        $tenureDays = max(0, (int) CarbonImmutable::parse($joinedOn)->diffInDays(CarbonImmutable::parse($periodEnd), false));
        $best = null;
        foreach ($e['tenure_bands'] ?? [] as $band) {
            $days = isset($band['from_days']) ? (int) $band['from_days'] : (isset($band['from_months']) ? (int) round($band['from_months'] * 30.4375) : (isset($band['min_days']) ? (int) $band['min_days'] : (isset($band['min_months']) ? (int) round($band['min_months'] * 30.4375) : (isset($band['min_years']) ? (int) round($band['min_years'] * 365.25) : null))));
            if ($days !== null && $tenureDays >= $days && ($best === null || $days >= $best[0])) {
                $best = [$days, Units::normalize($band['amount'] ?? $amount)];
            }
        }
        if ($best) {
            $units = $basis === 'monthly' ? Units::mul($best[1], (string) $monthsInPeriod) : $best[1];
            $lines[] = ['label' => 'Tenure band (from '.$best[0].' days of service)', 'units' => $units];
        }

        $proration = $e['proration'] ?? [];
        if (! empty($proration['enabled']) && $joinedOn > $periodStart) {
            $total = max(1, (int) CarbonImmutable::parse($periodStart)->diffInDays(CarbonImmutable::parse($periodEnd)) + 1);
            $worked = max(0, (int) CarbonImmutable::parse(min($joinedOn, $periodEnd))->diffInDays(CarbonImmutable::parse($periodEnd)) + 1);
            $units = Units::round(Units::div(Units::mul($units, (string) $worked), (string) $total));
            $lines[] = ['label' => 'Prorated for joining on '.$joinedOn.' ('.$worked.' of '.$total.' days)', 'units' => $units];
        }
        if (! empty($proration['rounding_step'])) {
            $step = Units::normalize($proration['rounding_step']);
            if (Units::cmp($step, '0') > 0) {
                $units = Units::mul((string) round((float) Units::div(Units::normalize($units), $step)), $step);
                $lines[] = ['label' => 'Rounded to the nearest '.$step, 'units' => $units];
            }
        }

        return ['units' => Units::normalize($units), 'lines' => $lines];
    }

    /** @return array{carried:string,lapsed:string,capped:bool,lines:array<int,string>} */
    public static function carryForward(array $rules, string $remaining, string $newEntitlement): array
    {
        $cf = $rules['carry_forward'] ?? ['mode' => 'none'];
        $mode = $cf['mode'] ?? 'none';
        $positive = Units::isNegative($remaining) ? '0.0000' : $remaining;
        $carried = match ($mode) {
            'full' => $positive,
            'capped' => Units::min($positive, $cf['cap'] ?? '0'),
            'percentage' => Units::round(Units::div(Units::mul($positive, $cf['percent'] ?? '0'), '100')),
            default => '0.0000',
        };
        if ($mode === 'percentage' && isset($cf['cap'])) {
            $carried = Units::min($carried, $cf['cap']);
        }
        $lines = ['Carry-forward mode "'.$mode.'": '.$carried.' of '.$positive.' carried.'];
        $capped = false;
        $max = $rules['limits']['max_balance'] ?? null;
        if ($max !== null && Units::cmp(Units::add($carried, $newEntitlement), $max) > 0) {
            $carried = Units::max('0', Units::sub($max, $newEntitlement));
            $capped = true;
            $lines[] = 'Closing balance limited to the maximum balance of '.Units::normalize($max).'.';
        }

        return ['carried' => Units::normalize($carried), 'lapsed' => Units::normalize(Units::sub($positive, $carried)), 'capped' => $capped, 'lines' => $lines];
    }

    /** Path-level diff of two rule documents. @return array<int,array{path:string,change:string,from:mixed,to:mixed}> */
    public static function diff(array $a, array $b): array
    {
        $fa = self::flatten($a);
        $fb = self::flatten($b);
        $out = [];
        foreach (array_unique(array_merge(array_keys($fa), array_keys($fb))) as $path) {
            if (! array_key_exists($path, $fa)) {
                $out[] = ['path' => $path, 'change' => 'added', 'from' => null, 'to' => $fb[$path]];
            } elseif (! array_key_exists($path, $fb)) {
                $out[] = ['path' => $path, 'change' => 'removed', 'from' => $fa[$path], 'to' => null];
            } elseif (! self::same($fa[$path], $fb[$path])) {
                $out[] = ['path' => $path, 'change' => 'changed', 'from' => $fa[$path], 'to' => $fb[$path]];
            }
        }
        usort($out, fn ($x, $y) => strcmp($x['path'], $y['path']));

        return $out;
    }

    private static function same(mixed $x, mixed $y): bool
    {
        if (is_numeric($x) && is_numeric($y) && ! is_bool($x) && ! is_bool($y)) {
            return Units::cmp($x, $y) === 0;
        }

        return $x === $y;
    }

    /** @return array<string,mixed> */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $path = $prefix === '' ? (string) $k : $prefix.'.'.$k;
            if (is_array($v) && $v !== [] && ! array_is_list($v)) {
                $out += self::flatten($v, $path);
            } else {
                $out[$path] = $v;
            }
        }

        return $out;
    }
}
