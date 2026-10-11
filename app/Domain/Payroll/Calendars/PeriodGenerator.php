<?php

namespace App\Domain\Payroll\Calendars;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** Pure period arithmetic for every frequency. rules: semi_monthly {split_day}, custom {ranges:[{start,end,label}]}, offsets (see dates()). */
final class PeriodGenerator
{
    public const PER_YEAR = ['weekly' => 52, 'biweekly' => 26, 'semi_monthly' => 24, 'monthly' => 12, 'four_weekly' => 13];

    /** @return array<int,array{start:string,end:string,label:string}> periods whose START lies in [from, to] */
    public function generate(string $frequency, string $from, string $to, ?string $anchor = null, array $rules = []): array
    {
        $from = CarbonImmutable::parse($from); $to = CarbonImmutable::parse($to);
        if ($to < $from) throw new InvalidArgumentException('End date is before start date.');
        if ($from->diffInDays($to) > 3700) throw new InvalidArgumentException('Generate at most ten years at a time.');
        $out = [];
        switch ($frequency) {
            case 'monthly':
                for ($d = $from->startOfMonth(); $d <= $to; $d = $d->addMonthNoOverflow()) $out[] = [$d->toDateString(), $d->endOfMonth()->toDateString(), $d->format('Y-m')];
                $out = array_values(array_filter($out, fn ($p) => $p[0] >= $from->toDateString()));
                break;
            case 'semi_monthly':
                $split = (int) ($rules['split_day'] ?? 15);
                if ($split < 1 || $split > 27) throw new InvalidArgumentException('Split day must be between 1 and 27.');
                for ($d = $from->startOfMonth(); $d <= $to; $d = $d->addMonthNoOverflow()) {
                    $out[] = [$d->toDateString(), $d->day($split)->toDateString(), $d->format('Y-m').'-A'];
                    $out[] = [$d->day($split + 1)->toDateString(), $d->endOfMonth()->toDateString(), $d->format('Y-m').'-B'];
                }
                $out = array_values(array_filter($out, fn ($p) => $p[0] >= $from->toDateString() && $p[0] <= $to->toDateString()));
                break;
            case 'weekly': case 'biweekly': case 'four_weekly':
                $len = ['weekly' => 7, 'biweekly' => 14, 'four_weekly' => 28][$frequency];
                if (! $anchor) throw new InvalidArgumentException('An anchor date (first period start) is required for this frequency.');
                $start = CarbonImmutable::parse($anchor);
                if ($start < $from) $start = $start->addDays((int) (ceil($start->diffInDays($from) / $len) * $len));
                $n = 0;
                for ($d = $start; $d <= $to; $d = $d->addDays($len)) {
                    if ($d >= $from) $out[] = [$d->toDateString(), $d->addDays($len - 1)->toDateString(), $d->toDateString().' +'.$len.'d'];
                    if (++$n > 400) break;
                }
                break;
            case 'custom':
                foreach ($rules['ranges'] ?? [] as $r) {
                    $s = CarbonImmutable::parse($r['start']); $e = CarbonImmutable::parse($r['end']);
                    if ($e < $s) throw new InvalidArgumentException('Custom range end is before its start.');
                    if ($s >= $from && $s <= $to) $out[] = [$s->toDateString(), $e->toDateString(), $r['label'] ?? $s->format('Y-m-d')];
                }
                usort($out, fn ($a, $b) => $a[0] <=> $b[0]);
                for ($i = 1; $i < count($out); $i++) if ($out[$i][0] <= $out[$i - 1][1]) throw new InvalidArgumentException('Custom ranges overlap.');
                break;
            default: throw new InvalidArgumentException('Unknown frequency.');
        }

        return array_map(fn ($p) => ['start' => $p[0], 'end' => $p[1], 'label' => $p[2]], $out);
    }

    /** Cutoff dates derived from calendar rules (offsets in days). */
    public function dates(string $end, array $rules): array
    {
        $e = CarbonImmutable::parse($end);
        $before = fn ($k, $d) => $e->subDays((int) ($rules[$k] ?? $d))->toDateString();
        $after = fn ($k, $d) => $e->addDays((int) ($rules[$k] ?? $d))->toDateString();

        return ['attendance_cutoff' => $before('attendance_cutoff_days_before_end', 0), 'leave_cutoff' => $before('leave_cutoff_days_before_end', 0), 'variable_pay_cutoff' => $before('variable_pay_cutoff_days_before_end', 2),
            'input_cutoff' => $before('input_cutoff_days_before_end', 0), 'approval_deadline' => $after('approval_days_after_end', 3), 'payment_date' => $after('payment_days_after_end', 5)];
    }
}
