<?php

namespace App\Domain\Payroll\Engine;

use App\Domain\Payroll\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * Pure payable-days resolution (joiner, leaver, LOP, unpaid leave, attendance). Decimal strings only; fully explained in `steps`.
 * Attendance payable days (Phase 3, already net of absence and unpaid leave) win; otherwise employment days minus LOP / unpaid leave.
 */
final class PayableDays
{
    /**
     * @param  array{period_start:string,period_end:string,employment_start?:?string,employment_end?:?string,attendance_payable?:?string,lop_days?:?string,days_basis?:?string}  $p
     * @return array{period_days:int,employment_days:int,employment_start:string,employment_end:string,basis:string,payable_days:string,lop_days:string,source:string,steps:array<int,string>}
     */
    public static function resolve(array $p): array
    {
        $ps = CarbonImmutable::parse($p['period_start']);
        $pe = CarbonImmutable::parse($p['period_end']);
        $periodDays = (int) $ps->diffInDays($pe) + 1;
        $es = ! empty($p['employment_start']) && $p['employment_start'] > $p['period_start'] ? $p['employment_start'] : $p['period_start'];
        $ee = ! empty($p['employment_end']) && $p['employment_end'] < $p['period_end'] ? $p['employment_end'] : $p['period_end'];
        $employment = $es > $ee ? 0 : (int) CarbonImmutable::parse($es)->diffInDays(CarbonImmutable::parse($ee)) + 1;
        $basis = isset($p['days_basis']) && $p['days_basis'] !== '' && $p['days_basis'] !== 'calendar' ? Decimal::str($p['days_basis'], 4) : Decimal::str($periodDays, 4);
        $lop = Decimal::str($p['lop_days'] ?? '0', 4);
        $steps = ["period $periodDays calendar days", "employed $employment day(s) from $es to $ee", "pay-day basis $basis"];
        if (isset($p['attendance_payable']) && $p['attendance_payable'] !== null) {
            $payable = Decimal::min($p['attendance_payable'], $employment);
            $source = 'attendance';
            $steps[] = 'attendance payable days '.Decimal::str($p['attendance_payable'], 4).' (capped at employment days); LOP/unpaid leave already reflected';
        } else {
            $payable = Decimal::max(Decimal::sub($employment, $lop), 0);
            $source = 'calendar';
            $steps[] = "employment days $employment minus LOP/unpaid $lop";
        }

        return ['period_days' => $periodDays, 'employment_days' => $employment, 'employment_start' => $es, 'employment_end' => $ee, 'basis' => $basis,
            'payable_days' => Decimal::str($payable, 4), 'lop_days' => $lop, 'source' => $source, 'steps' => $steps];
    }

    /** Factor (decimal string, scale 8) applied to a full-period amount for one compensation segment. */
    public static function factor(string $proration, bool $dependent, int $segDays, string $segPayable, array $d): string
    {
        $mode = $proration === 'none' && $dependent ? 'payable_days' : $proration;
        $f = match ($mode) {
            'calendar_days', 'working_days' => Decimal::div($segDays, $d['basis'], 8),
            'payable_days' => Decimal::div($segPayable, $d['basis'], 8),
            default => $d['employment_days'] > 0 ? Decimal::div($segDays, $d['employment_days'], 8) : Decimal::of(0),
        };

        return Decimal::str(Decimal::min($f, 1), 8);
    }
}
