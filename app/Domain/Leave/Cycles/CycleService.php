<?php

namespace App\Domain\Leave\Cycles;

use App\Domain\Audit\Audit;
use App\Domain\Leave\Models\LeaveCycle;
use App\Domain\Leave\Models\LeaveCyclePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CycleService
{
    public const KINDS = ['calendar', 'fiscal', 'anniversary', 'custom'];

    public function create(array $data): LeaveCycle
    {
        $row = $this->normalize($data);
        if (LeaveCycle::query()->where('code', $row['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'A cycle with this code already exists.']);
        }
        $cycle = LeaveCycle::create($row);
        Audit::record('created', 'leave', 'leave_cycle:'.$cycle->uuid, ['code' => $cycle->code, 'kind' => $cycle->kind]);

        return $cycle;
    }

    public function update(LeaveCycle $cycle, array $data): LeaveCycle
    {
        $structural = array_intersect(array_keys($data), ['kind', 'start_month', 'start_day', 'length_months']);
        if ($structural && ($this->isUsed($cycle))) {
            throw ValidationException::withMessages(['kind' => 'This cycle already has periods or is used by leave accounts, so its shape cannot change. Create a new cycle instead.']);
        }
        $merged = array_merge($cycle->only(['name', 'code', 'kind', 'start_month', 'start_day', 'length_months', 'config', 'active']), $data, ['code' => $cycle->code]);
        $cycle->fill($this->normalize($merged))->save();
        Audit::record('updated', 'leave', 'leave_cycle:'.$cycle->uuid, array_keys($data));

        return $cycle;
    }

    public function isUsed(LeaveCycle $cycle): bool
    {
        return LeaveCyclePeriod::query()->where('cycle_id', $cycle->id)->exists();
    }

    /**
     * Idempotently creates the periods covering [from, to]. Existing periods are never touched (their status belongs to
     * the ledger workstream). Anniversary cycles have per-employee periods and are not pre-generated.
     *
     * @return array{created:int,existing:int,periods:array<int,LeaveCyclePeriod>}
     */
    public function generate(LeaveCycle $cycle, string $from, string $to): array
    {
        if ($cycle->kind === 'anniversary') {
            throw ValidationException::withMessages(['cycle' => 'Anniversary cycles run per employee from the joining date; use period-for instead of generating shared periods.']);
        }
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'The end date cannot be before the start date.']);
        }
        $created = $existing = 0;
        $periods = [];
        DB::transaction(function () use ($cycle, $from, $to, &$created, &$existing, &$periods) {
            foreach (CycleCalculator::periodsBetween($this->shape($cycle), $from, $to) as $p) {
                $row = LeaveCyclePeriod::query()->where('cycle_id', $cycle->id)->where('start_date', $p['start'])->first();
                if ($row) {
                    $existing++;
                } else {
                    $row = LeaveCyclePeriod::create(['cycle_id' => $cycle->id, 'label' => $p['label'], 'start_date' => $p['start'], 'end_date' => $p['end'], 'status' => 'open']);
                    $created++;
                }
                $periods[] = $row;
            }
        });
        Audit::record('generated', 'leave', 'leave_cycle:'.$cycle->uuid, ['from' => $from, 'to' => $to, 'created' => $created]);

        return ['created' => $created, 'existing' => $existing, 'periods' => $periods];
    }

    /** @return array{start:string,end:string,label:string} */
    public function periodFor(LeaveCycle $cycle, string $date, ?string $anchor = null): array
    {
        return CycleCalculator::periodFor($this->shape($cycle), $date, $anchor);
    }

    private function shape(LeaveCycle $cycle): array
    {
        return ['kind' => $cycle->kind, 'start_month' => $cycle->start_month, 'start_day' => $cycle->start_day, 'length_months' => $cycle->length_months, 'config' => $cycle->config];
    }

    private function normalize(array $d): array
    {
        $kind = $d['kind'] ?? 'calendar';
        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'Cycle kind must be one of '.implode(', ', self::KINDS).'.']);
        }
        $month = (int) ($d['start_month'] ?? 1);
        $day = (int) ($d['start_day'] ?? 1);
        $length = (int) ($d['length_months'] ?? 12);
        if ($kind === 'calendar') {
            [$month, $day, $length] = [1, 1, 12];
        } elseif ($kind === 'fiscal' || $kind === 'anniversary') {
            $length = 12;
        }
        if ($month < 1 || $month > 12) {
            throw ValidationException::withMessages(['start_month' => 'Month must be 1 to 12.']);
        }
        if ($day < 1 || $day > 31 || $day > cal_days_in_month(CAL_GREGORIAN, $month, 2024)) {
            throw ValidationException::withMessages(['start_day' => 'That day does not exist in the chosen month.']);
        }
        if ($length < 1 || $length > 60) {
            throw ValidationException::withMessages(['length_months' => 'Length must be between 1 and 60 months.']);
        }

        return ['name' => $d['name'], 'code' => $d['code'], 'kind' => $kind, 'start_month' => $kind === 'anniversary' ? 1 : $month, 'start_day' => $kind === 'anniversary' ? 1 : $day,
            'length_months' => $length, 'config' => $d['config'] ?? null, 'active' => (bool) ($d['active'] ?? true)];
    }
}
