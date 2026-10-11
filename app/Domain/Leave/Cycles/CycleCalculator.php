<?php

namespace App\Domain\Leave\Cycles;

use Carbon\CarbonImmutable;

/**
 * Pure period maths for leave cycles: calendar, fiscal, anniversary (per employee anchor) and custom (any length in months).
 * A series starts at (anchor year, start_month, start_day) and repeats every `length_months`; days that do not exist in a
 * month (29 Feb, 31 Apr) are clamped to the month end. Nothing is stored here.
 */
final class CycleCalculator
{
    /**
     * @param  array{kind:string,start_month:int,start_day:int,length_months:int,config?:?array}  $cycle
     * @return array{start:string,end:string,label:string}
     */
    public static function periodFor(array $cycle, string $date, ?string $anchor = null): array
    {
        $kind = $cycle['kind'];
        $length = max(1, (int) ($cycle['length_months'] ?: 12));
        $month = (int) $cycle['start_month'];
        $day = (int) $cycle['start_day'];
        $baseYear = (int) (($cycle['config']['anchor_year'] ?? null) ?: 2000);
        if ($kind === 'calendar') {
            [$month, $day, $length] = [1, 1, 12];
        } elseif ($kind === 'fiscal') {
            $length = 12;
        } elseif ($kind === 'anniversary') {
            if (! $anchor) {
                throw new \InvalidArgumentException('An anniversary cycle needs the employee anchor date (joining date).');
            }
            $a = CarbonImmutable::parse($anchor);
            [$month, $day, $length, $baseYear] = [(int) $a->month, (int) $a->day, 12, (int) $a->year];
        }
        $on = CarbonImmutable::parse($date);
        $first = self::at($baseYear, $month, $day);
        $monthsApart = ($on->year - $first->year) * 12 + ($on->month - $first->month);
        $index = intdiv($monthsApart, $length);
        $start = self::shift($first, $index * $length, $day);
        if ($start->gt($on)) {
            $start = self::shift($first, --$index * $length, $day);
        } else {
            $next = self::shift($first, ($index + 1) * $length, $day);
            if ($next->lte($on)) {
                $start = $next;
                $index++;
            }
        }
        $end = self::shift($first, ($index + 1) * $length, $day)->subDay();

        return ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'label' => self::label($kind, $start, $end, $length)];
    }

    /** Every period (inclusive) that overlaps [from, to]. @return array<int,array{start:string,end:string,label:string}> */
    public static function periodsBetween(array $cycle, string $from, string $to, ?string $anchor = null): array
    {
        $out = [];
        $cursor = $from;
        for ($i = 0; $i < 400 && $cursor <= $to; $i++) {
            $p = self::periodFor($cycle, $cursor, $anchor);
            $out[] = $p;
            $cursor = CarbonImmutable::parse($p['end'])->addDay()->toDateString();
        }

        return $out;
    }

    private static function at(int $year, int $month, int $day): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1);

        return $first->setDay(min($day, $first->daysInMonth));
    }

    private static function shift(CarbonImmutable $first, int $months, int $day): CarbonImmutable
    {
        $m = $first->setDay(1)->addMonthsNoOverflow($months);

        return $m->setDay(min($day, $m->daysInMonth));
    }

    private static function label(string $kind, CarbonImmutable $start, CarbonImmutable $end, int $length): string
    {
        if ($kind === 'calendar') {
            return (string) $start->year;
        }
        if ($kind === 'fiscal') {
            return $start->year === $end->year ? 'FY '.$start->year : 'FY '.$start->year.'-'.substr((string) $end->year, 2);
        }

        return $start->toDateString().' to '.$end->toDateString();
    }
}
