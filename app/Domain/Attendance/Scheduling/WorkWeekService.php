<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Models\WorkWeek;
use App\Domain\Attendance\Models\WorkWeekDay;
use App\Domain\Audit\Audit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Work weeks are versioned and effective-dated: a "work week" is a code with versions (rows). A change from a date creates a NEW
 * version row and closes the previous one the day before; started versions are never edited.
 */
final class WorkWeekService
{
    public const PRESETS = [
        'mon_fri' => [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'off', 7 => 'off'],
        'mon_sat' => [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'full', 7 => 'off'],
        'sun_thu' => [7 => 'full', 1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'off', 6 => 'off'],
        'mon_fri_sat_half' => [1 => 'full', 2 => 'full', 3 => 'full', 4 => 'full', 5 => 'full', 6 => 'half', 7 => 'off'],
    ];

    public function create(array $in, User $actor): WorkWeek
    {
        $code = $this->code($in['code'] ?? '', null);
        $data = $this->clean($in);

        return DB::transaction(function () use ($data, $code, $actor) {
            $week = WorkWeek::create($data['week'] + ['code' => $code, 'version' => 1]);
            $this->writeDays($week, $data['days']);
            Audit::record('created', 'attendance', 'work_week:'.$week->uuid, ['code' => $code, 'version' => 1]);

            return $week->load('days');
        });
    }

    /** Creates the next version of the same work week from a date; closes the covering version the day before. */
    public function newVersion(WorkWeek $current, array $in, User $actor): WorkWeek
    {
        $from = $in['effective_from'] ?? null;
        $latest = WorkWeek::query()->where('code', $current->code)->orderByDesc('version')->first();
        $data = $this->clean($in + ['name' => $latest->name, 'description' => $latest->description, 'days' => $this->daysOf($latest)]);
        if ($from <= $latest->effective_from) {
            Fail::field('effective_from', "The new version must start after the current version starts ({$latest->effective_from}). Pick a later date.");
        }

        return DB::transaction(function () use ($latest, $data, $actor) {
            $affected = $this->affectedEmployees($latest->code, $data['week']['effective_from']);
            SchedulingEffects::assertRangeUnlocked($affected, $data['week']['effective_from'], SchedulingEffects::today());
            $end = CarbonImmutable::parse($data['week']['effective_from'])->subDay()->toDateString();
            WorkWeek::query()->where('code', $latest->code)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $data['week']['effective_from']))->update(['effective_to' => $end]);
            $week = WorkWeek::create($data['week'] + ['code' => $latest->code, 'version' => $latest->version + 1]);
            $this->writeDays($week, $data['days']);
            Audit::record('versioned', 'attendance', 'work_week:'.$week->uuid, ['code' => $week->code, 'version' => $week->version, 'effective_from' => $week->effective_from, 'previous_version' => $latest->version]);
            SchedulingEffects::recalculate($affected, $week->effective_from, SchedulingEffects::today(), $actor->id, 'work_week:'.$week->code.':'.$week->version);

            return $week->load('days');
        });
    }

    /** Metadata always; days and dates only while the version has not started (history is immutable). */
    public function update(WorkWeek $week, array $in, User $actor): WorkWeek
    {
        $started = $week->effective_from <= SchedulingEffects::today();
        $touchesPlan = array_intersect(array_keys($in), ['days', 'preset', 'effective_from']);
        if ($started && $touchesPlan) {
            Fail::business('version_started', "Version {$week->version} of this work week has already started. Create a new version from the date the change should apply so earlier attendance keeps its original rules.", 409);
        }
        $merged = $in + ['name' => $week->name, 'description' => $week->description, 'effective_from' => $week->effective_from, 'effective_to' => $week->effective_to, 'active' => $week->active];
        if (! $touchesPlan) {
            $merged['days'] = $this->daysOf($week);
        }
        $data = $this->clean($merged);

        return DB::transaction(function () use ($week, $data, $touchesPlan) {
            $week->update($data['week']);
            if ($touchesPlan) {
                $this->writeDays($week, $data['days']);
            }
            Audit::record('updated', 'attendance', 'work_week:'.$week->uuid, ['version' => $week->version]);

            return $week->load('days');
        });
    }

    public function delete(WorkWeek $week): void
    {
        $assigned = DB::table('work_week_assignments')->where('tenant_id', $week->tenant_id)->where('work_week_id', $week->id)->exists();
        if ($assigned || $week->effective_from <= SchedulingEffects::today()) {
            Fail::business('work_week_in_use', 'This work week is assigned or already in effect, so it cannot be deleted. Set it inactive instead.', 409);
        }
        DB::transaction(function () use ($week) {
            WorkWeekDay::query()->where('work_week_id', $week->id)->delete();
            Audit::record('deleted', 'attendance', 'work_week:'.$week->uuid, ['code' => $week->code, 'version' => $week->version]);
            $week->delete();
        });
    }

    /** @return array<string,mixed> */
    public function present(WorkWeek $week): array
    {
        $week->loadMissing('days');
        $names = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

        return ['uuid' => $week->uuid, 'name' => $week->name, 'code' => $week->code, 'version' => (int) $week->version, 'effective_from' => $week->effective_from, 'effective_to' => $week->effective_to,
            'description' => $week->description, 'active' => (bool) $week->active,
            'days' => $week->days->map(fn ($d) => ['weekday' => (int) $d->weekday, 'name' => $names[$d->weekday], 'type' => $d->type, 'minutes' => $d->minutes])->values()->all()];
    }

    private function code(string $code, ?WorkWeek $existing): string
    {
        $code = strtoupper(trim($code));
        if (! preg_match('/^[A-Z0-9_-]{1,40}$/', $code)) {
            Fail::field('code', 'Work week code must be 1-40 letters, numbers, dashes or underscores (for example MON-FRI).');
        }
        if (WorkWeek::query()->where('code', $code)->exists()) {
            Fail::field('code', "A work week with code {$code} already exists. Add a new version to it instead.");
        }

        return $code;
    }

    /** @return array<int,array{type:string,minutes:?int}> */
    private function daysOf(WorkWeek $week): array
    {
        $out = [];
        foreach ($week->days()->get() as $d) {
            $out[(int) $d->weekday] = ['type' => $d->type, 'minutes' => $d->minutes];
        }

        return $out;
    }

    /** @return array{week:array,days:array<int,array{type:string,minutes:?int}>} */
    private function clean(array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Give the work week a name, for example "Monday to Friday".';
        }
        $from = $in['effective_from'] ?? null;
        if (! is_string($from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
            $errors['effective_from'] = 'Enter the date this work week takes effect (YYYY-MM-DD).';
        }
        $to = $in['effective_to'] ?? null;
        if ($to !== null && (! is_string($to) || ! is_string($from) || $to < $from)) {
            $errors['effective_to'] = 'The end date must be on or after the start date.';
        }
        $days = [];
        if (isset($in['preset'])) {
            if (! isset(self::PRESETS[$in['preset']])) {
                $errors['preset'] = 'Preset must be one of: '.implode(', ', array_keys(self::PRESETS)).'.';
            } else {
                foreach (self::PRESETS[$in['preset']] as $weekday => $type) {
                    $days[$weekday] = ['type' => $type, 'minutes' => null];
                }
            }
        } else {
            $given = $in['days'] ?? [];
            foreach ((array) $given as $key => $day) {
                $weekday = is_array($day) && isset($day['weekday']) ? (int) $day['weekday'] : (int) $key;
                $type = is_array($day) ? ($day['type'] ?? null) : $day;
                $minutes = is_array($day) ? ($day['minutes'] ?? null) : null;
                if ($weekday < 1 || $weekday > 7) {
                    $errors['days'] = 'Weekdays run from 1 (Monday) to 7 (Sunday).';

                    continue;
                }
                if (! in_array($type, ['full', 'half', 'off', 'custom'], true)) {
                    $errors["days.$weekday"] = 'Each day must be full, half, off or custom.';

                    continue;
                }
                if ($type === 'custom' && (! is_numeric($minutes) || $minutes < 1 || $minutes > 1440)) {
                    $errors["days.$weekday"] = 'A custom day needs its duration in minutes (1-1440).';

                    continue;
                }
                if ($minutes !== null && (! is_numeric($minutes) || $minutes < 1 || $minutes > 1440)) {
                    $errors["days.$weekday"] = 'Minutes must be between 1 and 1440.';

                    continue;
                }
                $days[$weekday] = ['type' => $type, 'minutes' => in_array($type, ['half', 'custom'], true) && $minutes !== null ? (int) $minutes : null];
            }
        }
        if (! $errors) {
            for ($w = 1; $w <= 7; $w++) {
                if (! isset($days[$w])) {
                    $errors['days'] = 'Describe all seven weekdays (full, half, off or custom), or pick a preset such as mon_fri.';
                    break;
                }
            }
            if (! $errors && count(array_filter($days, fn ($d) => $d['type'] !== 'off')) === 0) {
                $errors['days'] = 'A work week needs at least one working day.';
            }
        }
        if ($errors) {
            Fail::fields($errors);
        }
        ksort($days);

        return ['week' => ['name' => $name, 'description' => $in['description'] ?? null, 'effective_from' => $from, 'effective_to' => $to, 'active' => (bool) ($in['active'] ?? true)], 'days' => $days];
    }

    private function writeDays(WorkWeek $week, array $days): void
    {
        WorkWeekDay::query()->where('work_week_id', $week->id)->delete();
        foreach ($days as $weekday => $day) {
            WorkWeekDay::create(['work_week_id' => $week->id, 'weekday' => $weekday, 'type' => $day['type'], 'minutes' => $day['minutes']]);
        }
    }

    /** @return array<int,int> employees reached by any assignment of any version of this work week code */
    private function affectedEmployees(string $code, string $from): array
    {
        if ($from > SchedulingEffects::today()) {
            return [];
        }
        $ids = [];
        $weekIds = WorkWeek::query()->where('code', $code)->pluck('id');
        foreach (DB::table('work_week_assignments')->where('tenant_id', app(\App\Domain\Tenancy\TenantContext::class)->id)->whereIn('work_week_id', $weekIds)->get(['target_type', 'target_id']) as $a) {
            $ids = array_merge($ids, TargetMembers::employeeIds($a->target_type, $a->target_id, $from, '9999-12-31'));
        }

        return array_values(array_unique($ids));
    }
}
