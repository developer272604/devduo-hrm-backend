<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Audit\Audit;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Holiday calendars (country / region / location / legal entity scoped), their holidays (mandatory | optional | restricted,
 * full or half day), recurring rules and the per-employee view Leave will consume (forEmployee).
 */
final class HolidayService
{
    public const TYPES = ['mandatory', 'optional', 'restricted'];

    public const PORTIONS = ['full', 'first_half', 'second_half'];

    public function createCalendar(array $in): HolidayCalendar
    {
        $data = $this->cleanCalendar($in, null);

        return DB::transaction(function () use ($data) {
            $calendar = HolidayCalendar::create($data);
            Audit::record('created', 'attendance', 'holiday_calendar:'.$calendar->uuid, ['code' => $calendar->code]);

            return $calendar;
        });
    }

    public function updateCalendar(HolidayCalendar $calendar, array $in): HolidayCalendar
    {
        $data = $this->cleanCalendar($in + $calendar->only(['name', 'code', 'country', 'region', 'location_id', 'legal_entity_id', 'description', 'active']), $calendar);

        return DB::transaction(function () use ($calendar, $data) {
            $calendar->update($data);
            Audit::record('updated', 'attendance', 'holiday_calendar:'.$calendar->uuid, ['code' => $calendar->code]);

            return $calendar;
        });
    }

    public function deleteCalendar(HolidayCalendar $calendar): void
    {
        $assigned = DB::table('employee_holiday_calendar_assignments')->where('tenant_id', $calendar->tenant_id)->where('calendar_id', $calendar->id)->exists();
        if ($assigned) {
            Fail::business('calendar_in_use', 'This calendar is assigned to employees, so it cannot be deleted. Mark it inactive instead.', 409);
        }
        DB::transaction(function () use ($calendar) {
            Holiday::query()->where('calendar_id', $calendar->id)->delete();
            Audit::record('deleted', 'attendance', 'holiday_calendar:'.$calendar->uuid, ['code' => $calendar->code]);
            $calendar->delete();
        });
    }

    public function addHoliday(HolidayCalendar $calendar, array $in, ?User $actor = null): Holiday
    {
        $data = $this->cleanHoliday($in, $calendar, null);

        return DB::transaction(function () use ($calendar, $data, $actor) {
            $affected = $this->touch($calendar, [$data['date']]);
            $holiday = Holiday::create($data + ['calendar_id' => $calendar->id]);
            Audit::record('created', 'attendance', 'holiday:'.$holiday->id, ['calendar' => $calendar->code, 'date' => $holiday->date, 'name' => $holiday->name]);
            SchedulingEffects::recalculate($affected, $holiday->date, $holiday->date, $actor?->id, 'holiday:'.$holiday->id);

            return $holiday;
        });
    }

    public function updateHoliday(HolidayCalendar $calendar, Holiday $holiday, array $in, ?User $actor = null): Holiday
    {
        $data = $this->cleanHoliday($in + $holiday->only(['name', 'date', 'type', 'portion', 'description', 'recurrence']), $calendar, $holiday);

        return DB::transaction(function () use ($calendar, $holiday, $data, $actor) {
            $dates = array_unique([$holiday->date, $data['date']]);
            $affected = $this->touch($calendar, $dates);
            $old = $holiday->date;
            $holiday->update($data);
            Audit::record('updated', 'attendance', 'holiday:'.$holiday->id, ['calendar' => $calendar->code, 'from_date' => $old, 'date' => $holiday->date]);
            SchedulingEffects::recalculate($affected, min($dates), max($dates), $actor?->id, 'holiday:'.$holiday->id);

            return $holiday;
        });
    }

    public function removeHoliday(HolidayCalendar $calendar, Holiday $holiday, ?User $actor = null): void
    {
        DB::transaction(function () use ($calendar, $holiday, $actor) {
            $affected = $this->touch($calendar, [$holiday->date]);
            Audit::record('deleted', 'attendance', 'holiday:'.$holiday->id, ['calendar' => $calendar->code, 'date' => $holiday->date, 'name' => $holiday->name]);
            $date = $holiday->date;
            $holiday->delete();
            SchedulingEffects::recalculate($affected, $date, $date, $actor?->id, 'holiday:removed:'.$calendar->id.':'.$date);
        });
    }

    /**
     * Generates the holidays of $year from the calendar's recurring rules. Idempotent: a holiday that already exists on the computed
     * date, or with the same name in that year, is skipped. @return array{created:array<int,array>,skipped:array<int,array>}
     */
    public function generateRecurring(HolidayCalendar $calendar, int $year, ?User $actor = null): array
    {
        if ($year < 2000 || $year > 2100) {
            Fail::field('year', 'Choose a year between 2000 and 2100.');
        }
        $templates = Holiday::query()->where('calendar_id', $calendar->id)->whereNotNull('recurrence')->orderBy('date')->get()
            ->unique(fn ($h) => $h->name.'|'.json_encode($h->recurrence));
        $existing = Holiday::query()->where('calendar_id', $calendar->id)->whereBetween('date', ["$year-01-01", "$year-12-31"])->get();
        $created = [];
        $skipped = [];
        DB::transaction(function () use ($templates, $existing, $year, $calendar, &$created, &$skipped) {
            foreach ($templates as $t) {
                $date = $this->occurrence($t->recurrence, $year);
                if ($date === null) {
                    $skipped[] = ['name' => $t->name, 'reason' => 'This rule has no valid date in '.$year.'.'];

                    continue;
                }
                if ($existing->contains('date', $date) || $existing->contains(fn ($h) => $h->name === $t->name)) {
                    $skipped[] = ['name' => $t->name, 'date' => $date, 'reason' => 'Already present for '.$year.'.'];

                    continue;
                }
                $h = Holiday::create(['calendar_id' => $calendar->id, 'date' => $date, 'name' => $t->name, 'type' => $t->type, 'portion' => $t->portion, 'description' => $t->description, 'recurrence' => $t->recurrence]);
                $existing->push($h);
                $created[] = ['name' => $h->name, 'date' => $h->date];
            }
            Audit::record('generated', 'attendance', 'holiday_calendar:'.$calendar->uuid, ['year' => $year, 'created' => count($created), 'skipped' => count($skipped)]);
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** Copies a calendar (optionally one year of it, re-dated to another year through the recurrence rules). */
    public function copyCalendar(HolidayCalendar $source, array $in): HolidayCalendar
    {
        $copy = $this->createCalendar(array_merge($source->only(['country', 'region', 'location_id', 'legal_entity_id', 'description']), ['name' => $in['name'] ?? $source->name.' (copy)', 'code' => $in['code'] ?? '']));
        $fromYear = isset($in['source_year']) ? (int) $in['source_year'] : null;
        $toYear = isset($in['target_year']) ? (int) $in['target_year'] : $fromYear;
        DB::transaction(function () use ($source, $copy, $fromYear, $toYear) {
            $query = Holiday::query()->where('calendar_id', $source->id)->orderBy('date');
            if ($fromYear) {
                $query->whereBetween('date', ["$fromYear-01-01", "$fromYear-12-31"]);
            }
            foreach ($query->get() as $h) {
                $date = $h->date;
                if ($fromYear && $toYear !== $fromYear) {
                    $date = $h->recurrence ? $this->occurrence($h->recurrence, $toYear) : $this->shiftYear($h->date, $toYear);
                }
                if ($date && ! Holiday::query()->where('calendar_id', $copy->id)->where('date', $date)->exists()) {
                    Holiday::create(['calendar_id' => $copy->id, 'date' => $date, 'name' => $h->name, 'type' => $h->type, 'portion' => $h->portion, 'description' => $h->description, 'recurrence' => $h->recurrence]);
                }
            }
            Audit::record('copied', 'attendance', 'holiday_calendar:'.$copy->uuid, ['source' => $source->code]);
        });

        return $copy;
    }

    /**
     * The holidays one employee gets in a range, resolved through THEIR effective calendar assignments as of each date (what Leave consumes).
     *
     * @return array<int,array{date:string,name:string,type:string,portion:string,calendar:?string,weekly_off:bool}>
     */
    public function forEmployee(int $employeeId, string $from, string $to): array
    {
        $decider = ScheduleDecider::load([$employeeId], $from, $to);
        $out = [];
        foreach (DatabaseScheduleResolver::dates($from, $to) as $date) {
            $d = $decider->decide($employeeId, $date);
            if ($d['holiday']) {
                $out[] = ['date' => $date, 'name' => $d['holiday']['name'], 'type' => $d['holiday']['type'], 'portion' => $d['holiday']['portion'], 'calendar' => $d['holiday']['calendar_code'],
                    'weekly_off' => $d['kind'] === 'holiday' && (bool) preg_grep('/weekly off/', $d['notes'])];
            }
        }

        return $out;
    }

    public function present(HolidayCalendar $calendar, bool $withCount = true): array
    {
        $tenant = app(TenantContext::class)->id;

        return ['uuid' => $calendar->uuid, 'name' => $calendar->name, 'code' => $calendar->code, 'country' => $calendar->country, 'region' => $calendar->region,
            'location_id' => $calendar->location_id, 'location' => $calendar->location_id ? DB::table('locations')->where('tenant_id', $tenant)->where('id', $calendar->location_id)->value('name') : null,
            'legal_entity_id' => $calendar->legal_entity_id, 'legal_entity' => $calendar->legal_entity_id ? DB::table('legal_entities')->where('tenant_id', $tenant)->where('id', $calendar->legal_entity_id)->value('name') : null,
            'description' => $calendar->description, 'active' => (bool) $calendar->active,
            'holiday_count' => $withCount ? Holiday::query()->where('calendar_id', $calendar->id)->count() : null];
    }

    public function presentHoliday(Holiday $h): array
    {
        return ['id' => $h->id, 'date' => $h->date, 'name' => $h->name, 'type' => $h->type, 'portion' => $h->portion, 'description' => $h->description, 'recurrence' => $h->recurrence];
    }

    // ---------------------------------------------------------------------------------------------------------------------------------

    /** Date of a recurrence rule in a year, or null. Rules: {"rule":"fixed","month":1,"day":26} | {"rule":"nth_weekday","month":11,"weekday":4,"nth":4} (nth -1 = last). */
    public function occurrence(array $rule, int $year): ?string
    {
        $month = (int) ($rule['month'] ?? 0);
        if ($month < 1 || $month > 12) {
            return null;
        }
        if (($rule['rule'] ?? '') === 'fixed') {
            $day = (int) ($rule['day'] ?? 0);

            return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
        }
        if (($rule['rule'] ?? '') === 'nth_weekday') {
            $weekday = (int) ($rule['weekday'] ?? 0);
            $nth = (int) ($rule['nth'] ?? 0);
            if ($weekday < 1 || $weekday > 7 || ! in_array($nth, [-1, 1, 2, 3, 4, 5], true)) {
                return null;
            }
            $dates = [];
            for ($d = CarbonImmutable::create($year, $month, 1); (int) $d->month === $month; $d = $d->addDay()) {
                if ((int) $d->isoWeekday() === $weekday) {
                    $dates[] = $d->toDateString();
                }
            }

            return $nth === -1 ? end($dates) : ($dates[$nth - 1] ?? null);
        }

        return null;
    }

    private function shiftYear(string $date, int $year): ?string
    {
        [, $m, $d] = array_map('intval', explode('-', $date));

        return checkdate($m, $d, $year) ? sprintf('%04d-%02d-%02d', $year, $m, $d) : null;
    }

    /** Lock-protects and returns the employees whose holiday changes on the dates (only dates up to today matter). */
    private function touch(HolidayCalendar $calendar, array $dates): array
    {
        $past = array_filter($dates, fn ($d) => $d <= SchedulingEffects::today());
        if (! $past) {
            return [];
        }
        $ids = [];
        foreach (DB::table('employee_holiday_calendar_assignments')->where('tenant_id', $calendar->tenant_id)->where('calendar_id', $calendar->id)->get(['target_type', 'target_id']) as $a) {
            $ids = array_merge($ids, TargetMembers::employeeIds($a->target_type, $a->target_id, min($past), max($past)));
        }
        $ids = array_values(array_unique($ids));
        SchedulingEffects::assertRangeUnlocked($ids, min($past), max($past));

        return $ids;
    }

    private function cleanCalendar(array $in, ?HolidayCalendar $existing): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($name === '') {
            $errors['name'] = 'Give the calendar a name, for example "India national holidays".';
        }
        if (! preg_match('/^[A-Z0-9_-]{1,40}$/', $code)) {
            $errors['code'] = 'Calendar code must be 1-40 letters, numbers, dashes or underscores.';
        } elseif (HolidayCalendar::query()->where('code', $code)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            $errors['code'] = "A calendar with code {$code} already exists.";
        }
        $country = isset($in['country']) && $in['country'] !== '' ? strtoupper((string) $in['country']) : null;
        if ($country !== null && ! preg_match('/^[A-Z]{2}$/', $country)) {
            $errors['country'] = 'Use a two-letter country code such as IN.';
        }
        $tenant = app(TenantContext::class)->id;
        foreach (['location_id' => 'locations', 'legal_entity_id' => 'legal_entities'] as $field => $table) {
            if (! empty($in[$field]) && ! DB::table($table)->where('tenant_id', $tenant)->where('id', $in[$field])->exists()) {
                $errors[$field] = 'We could not find that '.str_replace('_id', '', str_replace('_', ' ', $field)).' in your workspace.';
            }
        }
        if ($errors) {
            Fail::fields($errors);
        }

        return ['name' => $name, 'code' => $code, 'country' => $country, 'region' => $in['region'] ?? null, 'location_id' => $in['location_id'] ?? null, 'legal_entity_id' => $in['legal_entity_id'] ?? null,
            'description' => $in['description'] ?? null, 'active' => (bool) ($in['active'] ?? true)];
    }

    private function cleanHoliday(array $in, HolidayCalendar $calendar, ?Holiday $existing): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Give the holiday a name, for example "Republic Day".';
        }
        $date = $in['date'] ?? null;
        if (! is_string($date) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $errors['date'] = 'Enter the holiday date (YYYY-MM-DD).';
        } elseif (Holiday::query()->where('calendar_id', $calendar->id)->where('date', $date)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            $errors['date'] = 'This calendar already has a holiday on that date. A calendar can hold only one holiday per day.';
        }
        $type = $in['type'] ?? 'mandatory';
        if (! in_array($type, self::TYPES, true)) {
            $errors['type'] = 'Holiday type must be mandatory, optional or restricted.';
        }
        $portion = $in['portion'] ?? 'full';
        if (! in_array($portion, self::PORTIONS, true)) {
            $errors['portion'] = 'Choose full day, first half or second half.';
        }
        $recurrence = $in['recurrence'] ?? null;
        if ($recurrence !== null) {
            if (! is_array($recurrence) || $this->occurrence($recurrence, 2026) === null && $this->occurrence($recurrence, 2027) === null) {
                $errors['recurrence'] = 'Recurrence must be {"rule":"fixed","month":1,"day":26} or {"rule":"nth_weekday","month":11,"weekday":4,"nth":4} (nth -1 means the last one).';
            }
        }
        if ($errors) {
            Fail::fields($errors);
        }

        return ['name' => $name, 'date' => $date, 'type' => $type, 'portion' => $portion, 'description' => $in['description'] ?? null, 'recurrence' => $recurrence];
    }
}
