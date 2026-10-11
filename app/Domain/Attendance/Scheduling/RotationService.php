<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftRotation;
use App\Domain\Attendance\Models\ShiftRotationPattern;
use App\Domain\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Rotation patterns: a cycle of N days, each day a shift or a day off. The day index on a date is
 * ((daysBetween(anchor, date) mod N) + N) mod N + 1 (see ScheduleDecider), so dates before the anchor work too.
 */
final class RotationService
{
    /** @param array{name:string,code:string,cycle_length:int,description?:?string,days:array<int,mixed>,active?:bool} $in */
    public function create(array $in): ShiftRotation
    {
        $data = $this->clean($in, null);

        return DB::transaction(function () use ($data) {
            $rotation = ShiftRotation::create($data['rotation']);
            $this->writePatterns($rotation, $data['patterns']);
            Audit::record('created', 'attendance', 'shift_rotation:'.$rotation->uuid, ['code' => $rotation->code, 'cycle' => $rotation->cycle_length]);

            return $rotation->load('patterns');
        });
    }

    public function update(ShiftRotation $rotation, array $in): ShiftRotation
    {
        $planChange = isset($in['days']) || (isset($in['cycle_length']) && (int) $in['cycle_length'] !== (int) $rotation->cycle_length);
        if ($planChange && $this->startedUse($rotation)) {
            Fail::business('rotation_in_use', 'This rotation is already used by assignments that have started, so changing its pattern would rewrite past schedules. Create a new rotation and assign it from the change date.', 409);
        }
        $base = ['name' => $rotation->name, 'code' => $rotation->code, 'cycle_length' => $rotation->cycle_length, 'description' => $rotation->description, 'active' => $rotation->active, 'days' => $this->daysOf($rotation)];
        $data = $this->clean(array_merge($base, $in), $rotation);

        return DB::transaction(function () use ($rotation, $data, $planChange) {
            $rotation->update($data['rotation']);
            if ($planChange) {
                $this->writePatterns($rotation, $data['patterns']);
            }
            Audit::record('updated', 'attendance', 'shift_rotation:'.$rotation->uuid, ['code' => $rotation->code]);

            return $rotation->load('patterns');
        });
    }

    public function delete(ShiftRotation $rotation): void
    {
        if (DB::table('shift_assignments')->where('tenant_id', $rotation->tenant_id)->where('rotation_id', $rotation->id)->exists()) {
            Fail::business('rotation_in_use', 'This rotation is assigned to people, so it cannot be deleted. Deactivate it instead.', 409);
        }
        DB::transaction(function () use ($rotation) {
            ShiftRotationPattern::query()->where('rotation_id', $rotation->id)->delete();
            Audit::record('deleted', 'attendance', 'shift_rotation:'.$rotation->uuid, ['code' => $rotation->code]);
            $rotation->delete();
        });
    }

    /** @return array<string,mixed> */
    public function present(ShiftRotation $rotation): array
    {
        $rotation->loadMissing('patterns');
        $shifts = Shift::query()->whereIn('id', $rotation->patterns->pluck('shift_id')->filter())->get()->keyBy('id');

        return ['uuid' => $rotation->uuid, 'name' => $rotation->name, 'code' => $rotation->code, 'cycle_length' => (int) $rotation->cycle_length, 'description' => $rotation->description, 'active' => (bool) $rotation->active,
            'days' => $rotation->patterns->map(fn ($p) => ['day_index' => (int) $p->day_index, 'off' => $p->shift_id === null,
                'shift' => $p->shift_id ? ['uuid' => $shifts[$p->shift_id]->uuid, 'code' => $shifts[$p->shift_id]->code, 'name' => $shifts[$p->shift_id]->name] : null])->values()->all()];
    }

    private function startedUse(ShiftRotation $rotation): bool
    {
        return DB::table('shift_assignments')->where('tenant_id', $rotation->tenant_id)->where('rotation_id', $rotation->id)->where('effective_from', '<=', SchedulingEffects::today())->exists();
    }

    /** @return array<int,?string> day index => shift uuid or null */
    private function daysOf(ShiftRotation $rotation): array
    {
        $out = [];
        $shifts = Shift::query()->whereIn('id', $rotation->patterns()->pluck('shift_id')->filter())->pluck('uuid', 'id');
        foreach ($rotation->patterns()->get() as $p) {
            $out[] = $p->shift_id ? $shifts[$p->shift_id] : null;
        }

        return $out;
    }

    private function clean(array $in, ?ShiftRotation $existing): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($name === '') {
            $errors['name'] = 'Give the rotation a name, for example "Morning-Evening-Off".';
        }
        if (! preg_match('/^[A-Z0-9_-]{1,40}$/', $code)) {
            $errors['code'] = 'Rotation code must be 1-40 letters, numbers, dashes or underscores.';
        } elseif (ShiftRotation::query()->where('code', $code)->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            $errors['code'] = "A rotation with code {$code} already exists.";
        }
        $cycle = (int) ($in['cycle_length'] ?? 0);
        if ($cycle < 1 || $cycle > 366) {
            $errors['cycle_length'] = 'The cycle length must be between 1 and 366 days.';
        }
        $days = array_values((array) ($in['days'] ?? []));
        $patterns = [];
        if (! $errors) {
            if (count($days) !== $cycle) {
                $errors['days'] = "A {$cycle}-day cycle needs exactly {$cycle} day entries (a shift, or null/\"off\" for a day off); you sent ".count($days).'.';
            } else {
                $uuids = array_filter(array_map(fn ($d) => is_array($d) ? ($d['shift_uuid'] ?? null) : $d, $days), fn ($v) => $v !== null && $v !== 'off' && $v !== '');
                $found = Shift::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');
                foreach ($days as $i => $d) {
                    $uuid = is_array($d) ? ($d['shift_uuid'] ?? null) : $d;
                    if ($uuid === null || $uuid === 'off' || $uuid === '') {
                        $patterns[$i + 1] = null;

                        continue;
                    }
                    $shift = $found[$uuid] ?? null;
                    if (! $shift) {
                        $errors['days.'.($i + 1)] = 'Day '.($i + 1).': we could not find that shift.';
                    } elseif (! $shift->active) {
                        $errors['days.'.($i + 1)] = 'Day '.($i + 1).": shift {$shift->code} is inactive.";
                    } else {
                        $patterns[$i + 1] = $shift->id;
                    }
                }
                if (! $errors && ! array_filter($patterns)) {
                    $errors['days'] = 'A rotation needs at least one working day.';
                }
            }
        }
        if ($errors) {
            Fail::fields($errors);
        }

        return ['rotation' => ['name' => $name, 'code' => $code, 'cycle_length' => $cycle, 'description' => $in['description'] ?? null, 'active' => (bool) ($in['active'] ?? true)], 'patterns' => $patterns];
    }

    private function writePatterns(ShiftRotation $rotation, array $patterns): void
    {
        ShiftRotationPattern::query()->where('rotation_id', $rotation->id)->delete();
        foreach ($patterns as $index => $shiftId) {
            ShiftRotationPattern::create(['rotation_id' => $rotation->id, 'day_index' => $index, 'shift_id' => $shiftId]);
        }
    }
}
