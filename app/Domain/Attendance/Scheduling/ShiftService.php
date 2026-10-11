<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftSegment;
use App\Domain\Attendance\Support\PolicyRules;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Shift definitions: fixed, flexible and split; cross-midnight is derived (end <= start); segments carry day offsets.
 * Overrides for grace / late / early / break / overtime live in `rules` using the PolicyRules key names, plus two
 * scheduling-only keys: `weekly_off_days` (ISO weekdays that are the shift's own weekly off) and `allowed_location_ids`.
 */
final class ShiftService
{
    public const KINDS = ['fixed', 'flexible', 'split'];

    private const TIMING = ['timezone', 'start_time', 'end_time', 'segments', 'kind', 'flex_start_from', 'flex_start_to', 'core_start', 'core_end', 'required_minutes'];

    /** Parses "H:i" / "H:i:s" into minutes since midnight, or null when malformed. */
    public static function minutes(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $time, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function clock(int $minutes): string
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;

        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    public function create(array $in): Shift
    {
        $data = $this->normalize($in, null);

        return DB::transaction(function () use ($data) {
            $shift = Shift::create($data['shift']);
            $this->writeSegments($shift, $data['segments']);
            Audit::record('created', 'attendance', 'shift:'.$shift->uuid, ['code' => $shift->code, 'kind' => $shift->kind]);

            return $shift->load('segments');
        });
    }

    public function update(Shift $shift, array $in): Shift
    {
        $merged = array_merge($this->flatten($shift), $in);
        if (! array_key_exists('segments', $in) && (isset($in['start_time']) || isset($in['end_time'])) && count($merged['segments']) <= 1) {
            $merged['segments'] = []; // single-segment shifts follow their start/end times
        }
        $data = $this->normalize($merged, $shift);
        $timingChanged = false;
        foreach (self::TIMING as $field) {
            if (array_key_exists($field, $in)) {
                $before = $field === 'segments' ? $this->flatten($shift)['segments'] : $shift->{$field};
                $after = $field === 'segments' ? $data['segments_public'] : ($data['shift'][$field] ?? null);
                if (json_encode($this->plain($before)) !== json_encode($this->plain($after))) {
                    $timingChanged = true;
                }
            }
        }
        $usage = $this->usage($shift);
        if ($timingChanged && ($usage['assignments'] + $usage['roster_entries'] + $usage['rotations']) > 0 && empty($in['confirm_in_use_change'])) {
            Fail::business('shift_in_use', "Shift {$shift->code} is used by {$usage['assignments']} assignment(s), {$usage['rotations']} rotation day(s) and {$usage['roster_entries']} roster entr(ies). "
                .'Changing its timing re-interprets those schedules. Create a new shift instead, or resend with confirm_in_use_change=true after reviewing the impact.', 409, ['usage' => $usage]);
        }

        return DB::transaction(function () use ($shift, $data, $in) {
            $before = $shift->only(['name', 'start_time', 'end_time', 'kind', 'active']);
            $shift->update($data['shift']);
            $this->writeSegments($shift, $data['segments']);
            Audit::record('updated', 'attendance', 'shift:'.$shift->uuid, ['before' => $before, 'after' => $shift->only(['name', 'start_time', 'end_time', 'kind', 'active']), 'confirmed_in_use' => ! empty($in['confirm_in_use_change'])]);

            return $shift->load('segments');
        });
    }

    public function delete(Shift $shift): void
    {
        $usage = $this->usage($shift);
        if (array_sum($usage) > 0) {
            Fail::business('shift_in_use', "Shift {$shift->code} is in use and cannot be deleted. Deactivate it instead so history stays intact.", 409, ['usage' => $usage]);
        }
        DB::transaction(function () use ($shift) {
            ShiftSegment::query()->where('shift_id', $shift->id)->delete();
            $shift->delete();
            Audit::record('deleted', 'attendance', 'shift:'.$shift->uuid, ['code' => $shift->code]);
        });
    }

    /** @return array{assignments:int,rotations:int,roster_entries:int,swaps:int} */
    public function usage(Shift $shift): array
    {
        return [
            'assignments' => DB::table('shift_assignments')->where('tenant_id', $shift->tenant_id)->where('shift_id', $shift->id)->count(),
            'rotations' => DB::table('shift_rotation_patterns')->where('tenant_id', $shift->tenant_id)->where('shift_id', $shift->id)->count(),
            'roster_entries' => DB::table('roster_entries')->where('tenant_id', $shift->tenant_id)->where('shift_id', $shift->id)->count(),
            'swaps' => DB::table('shift_swap_requests')->where('tenant_id', $shift->tenant_id)->where(fn ($q) => $q->where('from_shift_id', $shift->id)->orWhere('to_shift_id', $shift->id))->count(),
        ];
    }

    /** Public representation (local wall times, never raw offsets only). */
    public function present(Shift $shift, bool $withUsage = false): array
    {
        $flat = $this->flatten($shift);
        $out = [
            'uuid' => $shift->uuid, 'name' => $shift->name, 'code' => $shift->code, 'kind' => $shift->kind, 'timezone' => $shift->timezone,
            'start_time' => substr($shift->start_time, 0, 5), 'end_time' => substr($shift->end_time, 0, 5), 'crosses_midnight' => (bool) $shift->crosses_midnight,
            'duration_minutes' => (int) $shift->duration_minutes, 'minimum_minutes' => $shift->minimum_minutes, 'half_day_minutes' => $shift->half_day_minutes, 'full_day_minutes' => $shift->full_day_minutes,
            'punch_window_before' => (int) $shift->punch_window_before, 'punch_window_after' => (int) $shift->punch_window_after,
            'flex_start_from' => $shift->flex_start_from ? substr($shift->flex_start_from, 0, 5) : null, 'flex_start_to' => $shift->flex_start_to ? substr($shift->flex_start_to, 0, 5) : null,
            'core_start' => $shift->core_start ? substr($shift->core_start, 0, 5) : null, 'core_end' => $shift->core_end ? substr($shift->core_end, 0, 5) : null,
            'required_minutes' => $shift->required_minutes, 'overtime_eligible' => (bool) $shift->overtime_eligible, 'rules' => $shift->rules ?? (object) [],
            'color_token' => $shift->color_token, 'active' => (bool) $shift->active, 'segments' => $flat['segments'],
        ];
        if ($withUsage) {
            $out['usage'] = $this->usage($shift);
        }

        return $out;
    }

    /** Current stored state in the input shape. */
    private function flatten(Shift $shift): array
    {
        $shift->loadMissing('segments');
        $segments = [];
        foreach ($shift->segments as $s) {
            $segments[] = ['kind' => $s->kind, 'paid' => (bool) $s->paid, 'start' => substr($s->start_time, 0, 5), 'end' => substr($s->end_time, 0, 5)];
        }

        return array_merge($shift->only(['name', 'code', 'kind', 'timezone', 'minimum_minutes', 'half_day_minutes', 'full_day_minutes', 'punch_window_before', 'punch_window_after',
            'required_minutes', 'overtime_eligible', 'color_token', 'active']), [
            'start_time' => substr($shift->start_time, 0, 5), 'end_time' => substr($shift->end_time, 0, 5),
            'flex_start_from' => $shift->flex_start_from ? substr($shift->flex_start_from, 0, 5) : null, 'flex_start_to' => $shift->flex_start_to ? substr($shift->flex_start_to, 0, 5) : null,
            'core_start' => $shift->core_start ? substr($shift->core_start, 0, 5) : null, 'core_end' => $shift->core_end ? substr($shift->core_end, 0, 5) : null,
            'rules' => $shift->rules ?? [], 'segments' => $segments,
        ]);
    }

    private function plain(mixed $v): mixed
    {
        return is_string($v) && preg_match('/^\d\d:\d\d:\d\d$/', $v) ? substr($v, 0, 5) : $v;
    }

    /** Validates and derives everything stored. @return array{shift:array,segments:array,segments_public:array} */
    private function normalize(array $in, ?Shift $existing): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($name === '') {
            $errors['name'] = 'Give the shift a name, for example "General shift".';
        }
        if (! preg_match('/^[A-Z0-9_-]{1,40}$/', $code)) {
            $errors['code'] = 'Shift code must be 1-40 letters, numbers, dashes or underscores (for example GEN or NIGHT-A).';
        } elseif (Shift::query()->where('code', $code)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            $errors['code'] = "A shift with code {$code} already exists. Choose a different code.";
        }
        $kind = $in['kind'] ?? 'fixed';
        if (! in_array($kind, self::KINDS, true)) {
            $errors['kind'] = 'Shift type must be fixed, flexible or split.';
        }
        $zone = $in['timezone'] ?? null;
        if (! TimeZones::isValid($zone)) {
            $errors['timezone'] = 'Choose a valid time zone such as Asia/Kolkata. The shift\'s own time zone defines its wall-clock times.';
        }
        foreach (['punch_window_before' => 120, 'punch_window_after' => 240] as $field => $default) {
            $v = $in[$field] ?? $default;
            if (! is_numeric($v) || $v < 0 || $v > 720) {
                $errors[$field] = 'The punch window must be between 0 and 720 minutes.';
            }
        }
        foreach (['minimum_minutes', 'half_day_minutes', 'full_day_minutes', 'required_minutes'] as $field) {
            if (isset($in[$field]) && (! is_numeric($in[$field]) || $in[$field] < 1 || $in[$field] > 1440)) {
                $errors[$field] = 'Enter a number of minutes between 1 and 1440.';
            }
        }
        if (isset($in['half_day_minutes'], $in['full_day_minutes']) && $in['half_day_minutes'] >= $in['full_day_minutes']) {
            $errors['half_day_minutes'] = 'Half-day minutes must be less than full-day minutes.';
        }
        if (isset($in['minimum_minutes'], $in['half_day_minutes']) && $in['minimum_minutes'] > $in['half_day_minutes']) {
            $errors['minimum_minutes'] = 'Minimum minutes cannot exceed half-day minutes.';
        }
        $rules = $in['rules'] ?? [];
        if (! is_array($rules)) {
            $errors['rules'] = 'Shift rules must be an object.';
            $rules = [];
        } else {
            $known = array_merge(array_keys(PolicyRules::DEFAULTS), ['weekly_off_days', 'allowed_location_ids']);
            foreach (array_diff(array_keys($rules), $known) as $key) {
                $errors["rules.$key"] = "Unknown rule \"$key\". Use the attendance policy rule names (late, early_exit, breaks, overtime, ...).";
            }
            foreach (PolicyRules::validate(array_diff_key($rules, ['weekly_off_days' => 1, 'allowed_location_ids' => 1])) as $path => $message) {
                $errors["rules.$path"] = $message;
            }
            if (isset($rules['weekly_off_days']) && (! is_array($rules['weekly_off_days']) || array_filter($rules['weekly_off_days'], fn ($d) => ! is_int($d) || $d < 1 || $d > 7))) {
                $errors['rules.weekly_off_days'] = 'Weekly off days must be a list of weekdays from 1 (Monday) to 7 (Sunday).';
            }
            if (isset($rules['allowed_location_ids']) && ! is_array($rules['allowed_location_ids'])) {
                $errors['rules.allowed_location_ids'] = 'Allowed locations must be a list of location ids.';
            }
        }

        $segmentsIn = $in['segments'] ?? [];
        $flex = ['flex_start_from' => $in['flex_start_from'] ?? null, 'flex_start_to' => $in['flex_start_to'] ?? null, 'core_start' => $in['core_start'] ?? null, 'core_end' => $in['core_end'] ?? null];
        foreach ($flex as $field => $v) {
            if ($v !== null && self::minutes($v) === null) {
                $errors[$field] = 'Use a 24-hour time such as 09:30.';
            }
        }
        $required = isset($in['required_minutes']) ? (int) $in['required_minutes'] : null;
        $start = self::minutes($in['start_time'] ?? null);
        $end = self::minutes($in['end_time'] ?? null);

        if ($kind === 'flexible') {
            $ff = self::minutes($flex['flex_start_from']);
            $ft = self::minutes($flex['flex_start_to']);
            if ($ff === null || $ft === null) {
                $errors['flex_start_from'] ??= 'A flexible shift needs the earliest and latest start time.';
            } elseif ($ft < $ff) {
                $errors['flex_start_to'] = 'The latest start must not be earlier than the earliest start.';
            }
            if (! $required) {
                $errors['required_minutes'] = 'A flexible shift needs the number of minutes to work (for example 480).';
            }
            $cs = self::minutes($flex['core_start']);
            $ce = self::minutes($flex['core_end']);
            if (($cs === null) !== ($ce === null)) {
                $errors['core_start'] = 'Set both core start and core end, or neither.';
            } elseif ($cs !== null && $ce !== null && $ff !== null && $ft !== null && $required) {
                if ($ce <= $cs) {
                    $errors['core_end'] = 'Core hours must end after they start.';
                } elseif ($cs < $ft) {
                    $errors['core_start'] = 'Core hours must begin after the flexible start window closes, otherwise people could start after core hours begin.';
                } elseif ($ce - $cs > $required) {
                    $errors['core_end'] = 'Core hours cannot be longer than the minutes the employee must work.';
                }
            }
            $start ??= $ff;
        } else {
            foreach (['flex_start_from', 'flex_start_to', 'core_start', 'core_end'] as $f) {
                if ($flex[$f] !== null) {
                    $errors[$f] = 'Flexible start and core hours only apply to flexible shifts.';
                }
            }
        }
        if ($start === null) {
            $errors['start_time'] = 'Enter the start time as a 24-hour time such as 09:00.';
        }
        if ($end === null && $kind === 'flexible' && $start !== null && $required) {
            $end = ($start + $required + $this->unpaidBreaks($segmentsIn)) % 1440;
        }
        if ($end === null) {
            $errors['end_time'] = 'Enter the end time as a 24-hour time such as 18:00.';
        }
        if (! is_array($segmentsIn)) {
            $errors['segments'] = 'Segments must be a list.';
            $segmentsIn = [];
        }
        if ($errors) {
            Fail::fields($errors);
        }

        // Derived span: end <= start crosses midnight (22:00 -> 06:00 = 480 minutes, 00:00 -> 00:00 = 24 hours).
        $duration = (($end - $start) % 1440 + 1440) % 1440 ?: 1440;
        $rows = $this->segmentRows($segmentsIn, $kind, $start, $duration, self::clock($start), self::clock($end), $errors);
        if ($kind === 'split') {
            $works = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'work'));
            if (count($works) < 2) {
                $errors['segments'] = 'A split shift needs at least two work segments (for example 08:00-12:00 and 16:00-20:00).';
            } else {
                $last = $works[count($works) - 1];
                $firstAbs = $works[0]['_s'];
                $span = $last['_e'] - $firstAbs;
                $duration = $span;
                $end = ($start + $firstAbs + $span) % 1440;
            }
        }
        if ($errors) {
            Fail::fields($errors);
        }
        $public = array_map(fn ($r) => ['kind' => $r['kind'], 'paid' => (bool) $r['paid'], 'start' => substr($r['start_time'], 0, 5), 'end' => substr($r['end_time'], 0, 5)], $rows);

        return [
            'shift' => [
                'name' => $name, 'code' => $code, 'kind' => $kind, 'timezone' => $zone, 'start_time' => self::clock($start), 'end_time' => self::clock($end), 'crosses_midnight' => $end <= $start,
                'duration_minutes' => $duration, 'minimum_minutes' => $in['minimum_minutes'] ?? null, 'half_day_minutes' => $in['half_day_minutes'] ?? null, 'full_day_minutes' => $in['full_day_minutes'] ?? null,
                'punch_window_before' => (int) ($in['punch_window_before'] ?? 120), 'punch_window_after' => (int) ($in['punch_window_after'] ?? 240),
                'flex_start_from' => $flex['flex_start_from'] ? self::clock(self::minutes($flex['flex_start_from'])) : null, 'flex_start_to' => $flex['flex_start_to'] ? self::clock(self::minutes($flex['flex_start_to'])) : null,
                'core_start' => $flex['core_start'] ? self::clock(self::minutes($flex['core_start'])) : null, 'core_end' => $flex['core_end'] ? self::clock(self::minutes($flex['core_end'])) : null,
                'required_minutes' => $kind === 'flexible' ? $required : ($in['required_minutes'] ?? null), 'overtime_eligible' => (bool) ($in['overtime_eligible'] ?? true),
                'rules' => $rules ?: null, 'color_token' => $in['color_token'] ?? null, 'active' => (bool) ($in['active'] ?? true),
            ],
            'segments' => $rows, 'segments_public' => $public,
        ];
    }

    private function unpaidBreaks(mixed $segments): int
    {
        $sum = 0;
        foreach (is_array($segments) ? $segments : [] as $s) {
            if (($s['kind'] ?? 'work') === 'break' && empty($s['paid']) && ($a = self::minutes($s['start'] ?? null)) !== null && ($b = self::minutes($s['end'] ?? null)) !== null) {
                $sum += (($b - $a) % 1440 + 1440) % 1440;
            }
        }

        return $sum;
    }

    /**
     * Converts local segment times to rows relative to the shift start, validates overlap and containment.
     *
     * @return array<int,array<string,mixed>>
     */
    private function segmentRows(array $segmentsIn, string $kind, int $start, int $duration, string $startClock, string $endClock, array &$errors): array
    {
        if (! $segmentsIn) {
            $segmentsIn = [['kind' => 'work', 'start' => substr($startClock, 0, 5), 'end' => substr($endClock, 0, 5)]];
        }
        $rows = [];
        $rel = fn (int $t) => (($t - $start) % 1440 + 1440) % 1440;
        foreach (array_values($segmentsIn) as $i => $s) {
            $segKind = $s['kind'] ?? 'work';
            $a = self::minutes($s['start'] ?? null);
            $b = self::minutes($s['end'] ?? null);
            if (! in_array($segKind, ['work', 'break'], true) || $a === null || $b === null) {
                $errors["segments.$i"] = 'Each segment needs a kind (work or break) and 24-hour start and end times.';

                continue;
            }
            $sAbs = $rel($a);
            $eAbs = $rel($b);
            if ($eAbs <= $sAbs) {
                $eAbs += 1440;
            }
            if ($a === $b) {
                $errors["segments.$i"] = 'A segment cannot start and end at the same time.';

                continue;
            }
            if ($eAbs > $duration && $kind !== 'split') {
                $errors["segments.$i"] = 'A segment runs past the end of the shift. Break times must sit inside the shift hours.';

                continue;
            }
            $rows[] = ['sequence' => 0, 'kind' => $segKind, 'paid' => $segKind === 'break' ? (bool) ($s['paid'] ?? false) : true, '_s' => $sAbs, '_e' => $eAbs,
                'start_time' => self::clock($a), 'end_time' => self::clock($b), 'start_day_offset' => intdiv($start + $sAbs, 1440), 'end_day_offset' => intdiv($start + $eAbs, 1440)];
        }
        if ($errors) {
            return [];
        }
        usort($rows, fn ($x, $y) => [$x['_s'], $x['_e']] <=> [$y['_s'], $y['_e']]);
        foreach ($rows as $i => &$row) {
            $row['sequence'] = $i + 1;
        }
        unset($row);
        foreach (['work', 'break'] as $k) {
            $same = array_values(array_filter($rows, fn ($r) => $r['kind'] === $k));
            for ($i = 1; $i < count($same); $i++) {
                if ($same[$i]['_s'] < $same[$i - 1]['_e']) {
                    $errors['segments'] = "Two {$k} segments overlap ({$same[$i - 1]['start_time']}-{$same[$i - 1]['end_time']} and {$same[$i]['start_time']}-{$same[$i]['end_time']}). Segments must not overlap.";
                }
            }
        }
        $works = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'work'));
        if (! $works) {
            $errors['segments'] = 'A shift needs at least one work segment.';
        }
        foreach ($rows as $r) {
            if ($r['kind'] === 'break' && ! array_filter($works, fn ($w) => $r['_s'] >= $w['_s'] && $r['_e'] <= $w['_e'])) {
                $errors['segments'] = "The break {$r['start_time']}-{$r['end_time']} must sit inside a work segment.";
            }
        }
        return $rows;
    }

    private function writeSegments(Shift $shift, array $rows): void
    {
        ShiftSegment::query()->where('shift_id', $shift->id)->delete();
        foreach ($rows as $r) {
            ShiftSegment::create(['shift_id' => $shift->id, 'sequence' => $r['sequence'], 'kind' => $r['kind'], 'paid' => $r['paid'], 'start_time' => $r['start_time'], 'end_time' => $r['end_time'],
                'start_day_offset' => $r['start_day_offset'], 'end_day_offset' => $r['end_day_offset']]);
        }
    }
}
