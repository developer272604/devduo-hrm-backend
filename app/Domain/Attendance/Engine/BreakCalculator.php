<?php

namespace App\Domain\Attendance\Engine;

/**
 * Step (e): how many minutes are deducted from gross presence as UNPAID break.
 *
 * Gross presence = first_in -> last_out presence (sessions PLUS the punched gaps between them, minus gaps that are just the
 * space between split-shift work segments). Therefore a punched break is already inside gross and is deducted here:
 *  - punch:       each punched gap is a break; its minutes are deducted EXCEPT the part that overlaps a scheduled PAID break
 *                 segment (and everything when `breaks.paid` is true).
 *  - fixed:       deduct the schedule's own UNPAID break segments when it defines any, otherwise `fixed_minutes`
 *                 (never both: no double deduction). Punched gaps are still reported but not deducted additionally.
 *  - auto_deduct: when break gaps were punched they are deducted like `punch`; when none were punched and gross >=
 *                 `auto_deduct_after_minutes`, deduct `auto_deduct_minutes`.
 *  - none:        nothing is deducted.
 */
final class BreakCalculator
{
    /**
     * @param  array<int,array{0:int,1:int}>  $gaps  punched gaps (effective, already split-gap free)
     * @param  array<int,array{0:int,1:int}>  $paidBreakSegments
     * @return array{minutes:int,mode:string,method:string,count:int,punched_minutes:int,scheduled_unpaid_minutes:int}
     */
    public static function compute(array $gaps, array $paidBreakSegments, int $scheduledUnpaidMinutes, array $rules, int $grossMinutes, bool $hasSessions): array
    {
        $mode = (string) ($rules['mode'] ?? 'punch');
        $allPaid = (bool) ($rules['paid'] ?? false);
        $count = count($gaps);
        $punchedSeconds = Intervals::seconds($gaps);
        $deduct = 0;
        $method = 'none';

        $punchDeduction = function () use ($gaps, $paidBreakSegments, $allPaid): int {
            if ($allPaid) {
                return 0;
            }
            $secs = Intervals::seconds($gaps) - Intervals::overlap($gaps, $paidBreakSegments);

            return intdiv(max(0, $secs), 60);
        };

        if ($mode === 'punch') {
            $deduct = $punchDeduction();
            $method = $allPaid ? 'punched_breaks_paid' : ($count > 0 ? 'punched_breaks' : 'no_break_punched');
        } elseif ($mode === 'fixed') {
            if ($hasSessions && ! $allPaid) {
                if ($scheduledUnpaidMinutes > 0) {
                    $deduct = $scheduledUnpaidMinutes;
                    $method = 'scheduled_unpaid_break_segments';
                } else {
                    $deduct = max(0, (int) ($rules['fixed_minutes'] ?? 0));
                    $method = 'fixed_minutes';
                }
            } else {
                $method = $allPaid ? 'fixed_paid' : 'no_presence';
            }
        } elseif ($mode === 'auto_deduct') {
            if ($count > 0) {
                $deduct = $punchDeduction();
                $method = 'punched_breaks';
            } elseif ($hasSessions && ! $allPaid && $grossMinutes >= (int) ($rules['auto_deduct_after_minutes'] ?? 360)) {
                $deduct = max(0, (int) ($rules['auto_deduct_minutes'] ?? 30));
                $method = 'auto_deduct';
            } else {
                $method = 'auto_deduct_not_triggered';
            }
        }

        return ['minutes' => $deduct, 'mode' => $mode, 'method' => $method, 'count' => $count, 'punched_minutes' => intdiv($punchedSeconds, 60), 'scheduled_unpaid_minutes' => $scheduledUnpaidMinutes];
    }
}
