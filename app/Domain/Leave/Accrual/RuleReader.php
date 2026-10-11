<?php

namespace App\Domain\Leave\Accrual;

use App\Domain\Leave\Models\LeaveAccrualRule;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use Carbon\CarbonImmutable;

/**
 * Normalises the per-type rule document returned by LeavePolicyResolver::rulesFor() into the shape the engines use.
 * Keys follow docs/api/leave-foundation.md "Rule document". When `entitlement` is absent the policy version's
 * leave_accrual_rules row (if any) is used, so both ways of authoring accrual are supported. Nothing here knows any country's law.
 */
final class RuleReader
{
    public const BASES = ['annual', 'monthly', 'weekly', 'daily', 'pay_period', 'anniversary', 'manual'];

    /**
     * @param  array{version_id:int,policy_id?:int,type_id:int,rules:array<string,mixed>,reason?:string}  $resolved
     * @return array<string,mixed>
     */
    public static function read(array $resolved): array
    {
        $rules = $resolved['rules'] ?? [];
        $e = $rules['entitlement'] ?? null;
        $fromTable = false;
        if (! is_array($e) || ! isset($e['basis'])) {
            $row = LeaveAccrualRule::query()->where('policy_version_id', $resolved['version_id'])->where('leave_type_id', $resolved['type_id'])->where('active', true)->orderBy('id')->first();
            if ($row) {
                $fromTable = true;
                $e = ['basis' => $row->frequency, 'amount' => $row->getRawOriginal('amount'), 'tenure_bands' => $row->tenure_bands, 'proration' => $row->proration,
                    'start_after_days' => $row->start_after_days, 'max_accumulation' => $row->getRawOriginal('max_accumulation')] + (is_array($e) ? $e : []);
            }
        }
        $e = is_array($e) ? $e : [];
        $limits = $rules['limits'] ?? [];
        $elig = $rules['eligibility'] ?? [];
        $basis = in_array($e['basis'] ?? null, self::BASES, true) ? $e['basis'] : 'manual';
        $prorate = is_array($e['proration'] ?? null) ? $e['proration'] : [];

        return [
            'version_id' => (int) $resolved['version_id'], 'type_id' => (int) $resolved['type_id'], 'from_table' => $fromTable,
            'basis' => $basis,
            'amount' => Units::normalize($e['amount'] ?? 0),
            'timing' => $e['timing'] ?? (in_array($basis, ['annual', 'anniversary'], true) ? 'start' : 'end'),
            'tenure_bands' => self::bands($e['tenure_bands'] ?? []),
            'pay_period' => is_array($e['pay_period'] ?? null) ? $e['pay_period'] : [],
            'proration' => [
                'joiner' => $prorate['joiner'] ?? 'daily', 'leaver' => $prorate['leaver'] ?? 'daily',
                'joiner_cutoff_day' => (int) ($prorate['joiner_cutoff_day'] ?? $prorate['cutoff_day'] ?? 15), 'leaver_cutoff_day' => (int) ($prorate['leaver_cutoff_day'] ?? $prorate['cutoff_day'] ?? 15),
                'probation' => $prorate['probation'] ?? 'accrue', 'rounding' => $prorate['rounding'] ?? 'none',
            ],
            'start_after_days' => (int) ($e['start_after_days'] ?? $elig['min_tenure_days'] ?? 0),
            'waiting' => ($e['waiting'] ?? 'defer') === 'forfeit' ? 'forfeit' : 'defer',
            'max_per_period' => self::nullableUnits($e['max_per_period'] ?? null),
            'max_balance' => self::nullableUnits($limits['max_balance'] ?? $e['max_accumulation'] ?? null),
            'negative_limit' => self::nullableUnits($limits['negative_limit'] ?? $rules['negative_balance'] ?? null),
            'advance_leave' => self::flag($limits['advance_leave'] ?? false),
            'cycle' => $rules['cycle'] ?? $e['cycle'] ?? $rules['cycle_code'] ?? null,
            'expiry' => is_array($rules['expiry'] ?? null) ? $rules['expiry'] : [],
            'carry' => is_array($rules['carry_forward'] ?? null) ? $rules['carry_forward'] : ['mode' => 'none'],
            'consumption_order' => \App\Domain\Leave\Expiry\ConsumptionPlanner::normalizeOrder($rules['consumption_order'] ?? null),
        ];
    }

    /** advance_leave is a flag; a numeric value (older documents) counts as on when above zero. */
    private static function flag(mixed $v): bool
    {
        return is_numeric($v) ? Units::cmp(Units::normalize($v), '0') > 0 : (bool) $v;
    }

    public static function nullableUnits(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : Units::normalize($v);
    }

    /** @return array<int,array{from_months:int,to_months:?int,amount:string}> */
    private static function bands(mixed $bands): array
    {
        $out = [];
        foreach (is_array($bands) ? $bands : [] as $b) {
            if (is_array($b) && isset($b['amount'])) {
                // A's rule validator authors bands in days (from_days / to_days); months are accepted too. Days convert at 30.4375 per month.
                $from = isset($b['from_months']) ? (int) $b['from_months'] : (isset($b['from_days']) ? (int) floor($b['from_days'] / 30.4375) : 0);
                $to = isset($b['to_months']) ? (int) $b['to_months'] : (isset($b['to_days']) ? (int) floor($b['to_days'] / 30.4375) : null);
                $out[] = ['from_months' => $from, 'to_months' => $to, 'amount' => Units::normalize($b['amount'])];
            }
        }
        usort($out, fn ($a, $b) => $a['from_months'] <=> $b['from_months']);

        return $out;
    }

    /** Expiry date for a credit effective on $effective inside a period ending $periodEnd, or null (never expires). */
    public static function expiryFor(array $rule, string $effective, string $periodEnd): ?string
    {
        $x = $rule['expiry'];
        $d = CarbonImmutable::parse($effective);
        if (! empty($x['valid_days'])) {
            return $d->addDays((int) $x['valid_days'])->toDateString();
        }
        if (! empty($x['valid_months'])) {
            return $d->addMonthsNoOverflow((int) $x['valid_months'])->toDateString();
        }
        if (! empty($x['at_period_end'])) {
            return $periodEnd;
        }

        return null;
    }

    /** Policy rounding: none | nearest|up|down + _half|_whole (e.g. nearest_half). */
    public static function roundUnits(string $units, string $mode): string
    {
        if ($mode === 'none' || $mode === '') {
            return $units;
        }
        [$how, $size] = array_pad(explode('_', $mode, 2), 2, 'whole');
        $step = $size === 'half' ? '0.5' : '1';
        $q = bcdiv($units, $step, 8);
        $floor = bcadd($q, '0', 0);
        $frac = bcsub($q, $floor, 8);
        $n = match ($how) {
            'up' => bccomp($frac, '0', 8) > 0 ? bcadd($floor, '1', 0) : $floor,
            'down' => $floor,
            default => bccomp($frac, '0.5', 8) >= 0 ? bcadd($floor, '1', 0) : $floor,
        };

        return Units::normalize(bcmul($n, $step, 4));
    }

    public static function defaultNegativeLimit(): string
    {
        return LeaveSettings::get('balance.allow_negative_default') ? Units::normalize(LeaveSettings::get('balance.default_negative_limit', '0')) : '0.0000';
    }
}
