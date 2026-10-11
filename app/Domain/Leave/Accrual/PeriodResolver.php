<?php

namespace App\Domain\Leave\Accrual;

use App\Domain\Leave\Models\LeaveCycle;
use App\Domain\Leave\Models\LeaveCyclePeriod;
use Carbon\CarbonImmutable;

/**
 * Finds the leave period (the span one account covers) that contains a date. Period structure is policy DATA:
 * `rules.cycle` = a cycle code (string) or {code} / {kind: anniversary|calendar, start_month, start_day, length_months}.
 * No cycle given => calendar year. Anniversary => the 12 months starting on the employee's joining anniversary.
 * The period is calculated from the cycle definition (so it works before generated leave_cycle_periods rows exist);
 * `cycle_period_id` is filled in when a matching generated period exists.
 */
final class PeriodResolver
{
    /** @return array{start:string,end:string,cycle_id:?int,cycle_period_id:?int,label:string,kind:string} */
    public function forDate(?string $joinedOn, array|string|null $cycle, string $date): array
    {
        $spec = $this->spec($joinedOn, $cycle);
        $d = CarbonImmutable::parse($date);
        $anchor = $this->anchor($spec['start_month'], $spec['start_day'], $d->year);
        if ($anchor->gt($d)) {
            $anchor = $this->anchor($spec['start_month'], $spec['start_day'], $d->year - 1);
        }
        $start = $anchor;
        $guard = 0;
        while ($start->addMonthsNoOverflow($spec['length'])->lte($d) && $guard++ < 600) {
            $start = $start->addMonthsNoOverflow($spec['length']);
        }
        $end = $start->addMonthsNoOverflow($spec['length'])->subDay();
        $periodId = $spec['cycle_id'] ? LeaveCyclePeriod::query()->where('cycle_id', $spec['cycle_id'])->where('start_date', $start->toDateString())->value('id') : null;

        return ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'cycle_id' => $spec['cycle_id'], 'cycle_period_id' => $periodId ? (int) $periodId : null,
            'label' => $start->format('Y-m-d').' to '.$end->format('Y-m-d'), 'kind' => $spec['kind']];
    }

    /** @return array{kind:string,start_month:int,start_day:int,length:int,cycle_id:?int} */
    private function spec(?string $joinedOn, array|string|null $cycle): array
    {
        $code = is_string($cycle) ? $cycle : ($cycle['code'] ?? null);
        if ($code && ($model = LeaveCycle::query()->where('code', $code)->first())) {
            if ($model->kind === 'anniversary' && $joinedOn) {
                $j = CarbonImmutable::parse($joinedOn);

                return ['kind' => 'anniversary', 'start_month' => $j->month, 'start_day' => $j->day, 'length' => max(1, (int) $model->length_months), 'cycle_id' => $model->id];
            }

            return ['kind' => $model->kind, 'start_month' => (int) $model->start_month, 'start_day' => (int) $model->start_day, 'length' => max(1, (int) $model->length_months), 'cycle_id' => $model->id];
        }
        $arr = is_array($cycle) ? $cycle : [];
        $kind = $arr['kind'] ?? 'calendar';
        if ($kind === 'anniversary' && $joinedOn) {
            $j = CarbonImmutable::parse($joinedOn);

            return ['kind' => $kind, 'start_month' => $j->month, 'start_day' => $j->day, 'length' => (int) ($arr['length_months'] ?? 12) ?: 12, 'cycle_id' => null];
        }

        return ['kind' => $kind, 'start_month' => max(1, min(12, (int) ($arr['start_month'] ?? 1))), 'start_day' => max(1, min(31, (int) ($arr['start_day'] ?? 1))),
            'length' => max(1, (int) ($arr['length_months'] ?? 12)), 'cycle_id' => null];
    }

    private function anchor(int $month, int $day, int $year): CarbonImmutable
    {
        $first = CarbonImmutable::create($year, $month, 1);

        return $first->setDay(min($day, $first->daysInMonth));
    }
}
