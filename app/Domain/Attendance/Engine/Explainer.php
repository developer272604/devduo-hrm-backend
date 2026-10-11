<?php

namespace App\Domain\Attendance\Engine;

use App\Domain\Attendance\Support\AttendanceStatus;
use App\Domain\Attendance\Support\ExceptionType;
use Carbon\CarbonImmutable;

/**
 * "Why is this attendance marked this way?" in plain language. PURE functions only: every sentence is derived from the
 * deterministic result/steps; nothing is looked up, nothing is guessed. Times are shown in the SHIFT timezone with the zone
 * abbreviation (e.g. "09:00–18:00 IST (Asia/Kolkata)").
 *
 * Output: {headline, status, status_label, confidence, lines:[{label,value,rule?}], facts:[string], actions:[string]}.
 * Integrity wording is neutral ("Attendance integrity review required"); nothing here ever accuses anyone.
 */
final class Explainer
{
    /**
     * @param  array<string,mixed>  $result  CalculationResult::toArray() plus a `steps` key
     * @return array<string,mixed>
     */
    public static function explain(CalculationInput $input, array $result): array
    {
        $tz = $input->schedule->timezone;
        $steps = $result['steps'] ?? [];
        $status = (string) $result['status'];
        $label = AttendanceStatus::label($status);
        $lines = [];

        $sched = self::step($steps, 'schedule');
        $working = $input->schedule->isWorkingDay();
        if ($working && ($sched['outcome']['start'] ?? null) && ($sched['outcome']['end'] ?? null)) {
            $code = $input->schedule->shiftCode ? ' ['.$input->schedule->shiftCode.']' : '';
            $lines[] = ['label' => 'Scheduled shift', 'value' => self::time($sched['outcome']['start'], $tz).'–'.self::time($sched['outcome']['end'], $tz).' '.self::abbr($sched['outcome']['start'], $tz).' ('.$tz.')'.$code, 'rule' => 'schedule'];
        } elseif (! $working) {
            $kindLabel = AttendanceStatus::label($input->schedule->kind);
            $lines[] = ['label' => 'Day type', 'value' => $kindLabel.($input->schedule->holidayName ? ' – '.$input->schedule->holidayName : ''), 'rule' => 'schedule.kind'];
        } else {
            $lines[] = ['label' => 'Scheduled shift', 'value' => 'No shift assigned', 'rule' => 'schedule.shift'];
        }

        if ($working && ($sched['outcome']['required_minutes'] ?? 0) > 0) {
            $lines[] = ['label' => 'Required full day', 'value' => self::duration((int) $sched['outcome']['required_minutes']), 'rule' => 'hours.full_day_minutes'];
            $lines[] = ['label' => 'Required half day', 'value' => self::duration((int) ($sched['outcome']['half_day_minutes'] ?? 0)), 'rule' => 'hours.half_day_minutes'];
        }

        if (! empty($result['first_in'])) {
            $lines[] = ['label' => 'First in', 'value' => self::time($result['first_in'], $tz).' '.self::abbr($result['first_in'], $tz)];
        }
        if (! empty($result['last_out'])) {
            $lines[] = ['label' => 'Last out', 'value' => self::time($result['last_out'], $tz).' '.self::abbr($result['last_out'], $tz)];
        }

        $breaks = self::step($steps, 'breaks');
        if ((int) $result['break_minutes'] > 0) {
            $lines[] = ['label' => 'Time at work (gross)', 'value' => self::duration((int) $result['gross_minutes'])];
            $lines[] = ['label' => 'Unpaid break', 'value' => self::duration((int) $result['break_minutes']).' ('.self::breakMethod((string) ($breaks['outcome']['method'] ?? '')).')', 'rule' => 'breaks.mode'];
        }
        $lines[] = ['label' => 'Net work', 'value' => self::duration((int) $result['net_minutes']), 'rule' => 'hours'];

        $late = self::step($steps, 'late');
        if ($late !== null) {
            $in = $late['inputs'];
            if ((int) $late['outcome']['late_minutes'] > 0) {
                $lines[] = ['label' => 'Late', 'value' => self::duration((int) $late['outcome']['late_minutes']).' (grace '.self::duration((int) $in['grace_minutes']).', '.self::graceText((string) $in['grace_mode']).')', 'rule' => 'late.grace_minutes'];
            } else {
                $lines[] = ['label' => 'Arrival', 'value' => 'On time (arrived '.self::time($in['arrival'], $tz).', grace '.self::duration((int) $in['grace_minutes']).')', 'rule' => 'late.grace_minutes'];
            }
        }
        $early = self::step($steps, 'early_exit');
        if ($early !== null && isset($early['outcome']['early_exit_minutes']) && (int) $early['outcome']['early_exit_minutes'] > 0) {
            $in = $early['inputs'];
            $lines[] = ['label' => 'Early exit', 'value' => self::duration((int) $early['outcome']['early_exit_minutes']).' (grace '.self::duration((int) $in['grace_minutes']).', '.self::graceText((string) $in['grace_mode']).')', 'rule' => 'early_exit.grace_minutes'];
        }

        if ((int) $result['overtime_minutes'] > 0) {
            $parts = [];
            foreach (($result['overtime'] ?? []) as $type => $m) {
                if ((int) $m > 0) {
                    $parts[] = str_replace('_', ' ', (string) $type).' '.self::duration((int) $m);
                }
            }
            $lines[] = ['label' => 'Overtime (calculated)', 'value' => self::duration((int) $result['overtime_minutes']).' ('.implode(', ', $parts).')', 'rule' => 'overtime.enabled'];
            $lines[] = ['label' => 'Overtime (approved)', 'value' => $input->approvedOvertimeMinutes > 0 ? self::duration($input->approvedOvertimeMinutes) : 'Not approved yet', 'rule' => 'overtime.requires_approval'];
        } elseif ($input->approvedOvertimeMinutes > 0) {
            $lines[] = ['label' => 'Overtime (approved)', 'value' => self::duration($input->approvedOvertimeMinutes), 'rule' => 'overtime.requires_approval'];
        }

        $lines[] = ['label' => 'Policy', 'value' => $input->policyLabel !== null ? '"'.$input->policyLabel.'"' : 'No policy assigned (default rules used)', 'rule' => 'policy'];
        $lines[] = ['label' => 'Result', 'value' => $label, 'rule' => 'status'];

        return [
            'headline' => $label,
            'status' => $status,
            'status_label' => $label,
            'confidence' => (string) $result['confidence'],
            'lines' => $lines,
            'facts' => self::facts($input, $result, $steps, $tz),
            'actions' => self::actions($result),
        ];
    }

    /** @return array<int,string> */
    private static function facts(CalculationInput $input, array $result, array $steps, string $tz): array
    {
        $facts = [];
        $st = self::step($steps, 'status');
        $reason = (string) ($st['outcome']['reason'] ?? '');
        if ($reason !== '') {
            $facts[] = ucfirst($reason).'.';
        }
        $ctx = $st['inputs'] ?? [];
        if (isset($ctx['required_minutes'], $ctx['net_minutes']) && $input->schedule->isWorkingDay() && (int) $ctx['required_minutes'] > 0) {
            $facts[] = 'Net work of '.self::duration((int) $ctx['net_minutes']).' compared with '.self::duration((int) $ctx['required_minutes']).' for a full day and '.self::duration((int) $ctx['half_day_minutes']).' for a half day.';
        }

        $norm = self::step($steps, 'punches.normalize');
        if ($norm !== null) {
            $dups = $norm['outcome']['duplicates_dropped'] ?? [];
            if ($dups !== []) {
                $facts[] = count($dups).' duplicate punch(es) within '.$norm['inputs']['duplicate_window_seconds'].' seconds were ignored (kept the earliest): '.implode(', ', array_column($dups, 'dropped')).'.';
            }
            $out = $norm['outcome']['outside_window'] ?? [];
            if ($out !== []) {
                $facts[] = count($out).' punch(es) fell outside the accepted time window for this day and were not used: '.implode(', ', $out).'.';
            }
            $facts[] = 'Punches used: '.($result['punch_ids'] === [] ? 'none' : implode(', ', $result['punch_ids'])).'.';
        }

        $fill = self::step($steps, 'missing_punch');
        if ($fill !== null && isset($fill['outcome']['auto_filled'])) {
            $facts[] = 'A missing punch was auto-filled by the approved rule "'.$fill['inputs']['rule'].'" at '.self::time($fill['outcome']['time'], $tz).'.';
        }
        if ($result['flags']['half_day_by_late'] ?? false) {
            $facts[] = 'Arrival was later than the limit after which a full day becomes a half day.';
        }
        if ($result['flags']['core_hours_not_met'] ?? false) {
            $facts[] = 'The core hours were not fully covered, so the day cannot count as a full day.';
        }
        if ($result['flags']['no_policy_defaults'] ?? false) {
            $facts[] = 'No attendance policy applied, so the default rules were used.';
        }
        foreach ($result['exceptions'] as $e) {
            $facts[] = $e['title'].($e['severity'] === 'info' ? ' (for information)' : '').'.';
        }
        if ($input->approvedOvertimeMinutes > 0) {
            $facts[] = 'Approved overtime: '.self::duration($input->approvedOvertimeMinutes).'.';
        }

        return $facts;
    }

    /** @return array<int,string> */
    private static function actions(array $result): array
    {
        $actions = [];
        $types = array_column($result['exceptions'], 'type');
        $status = (string) $result['status'];
        if (in_array($status, [AttendanceStatus::MISSING_PUNCH, AttendanceStatus::INCOMPLETE], true) || array_intersect($types, [ExceptionType::MISSING_IN, ExceptionType::MISSING_OUT, ExceptionType::UNPAIRED_PUNCH]) !== []) {
            $actions[] = 'Request regularization';
        }
        if ($status === AttendanceStatus::ABSENT && $result['pairs'] !== []) {
            $actions[] = 'Request regularization';
        }
        if (in_array(ExceptionType::NO_POLICY, $types, true)) {
            $actions[] = 'Ask HR to assign an attendance policy';
        }
        if (in_array(ExceptionType::NO_SHIFT, $types, true)) {
            $actions[] = 'Ask HR or your manager to assign a shift';
        }
        if (array_intersect($types, [ExceptionType::OUTSIDE_GEOFENCE, ExceptionType::INTEGRITY_REVIEW]) !== []) {
            $actions[] = 'Attendance integrity review required (manager or HR)';
        }
        if (in_array(ExceptionType::OVERTIME, $types, true)) {
            $actions[] = 'Request overtime approval';
        }
        if ($status === AttendanceStatus::PENDING) {
            $actions[] = 'Wait for the day to finish or for pending punches to arrive';
        }

        return array_values(array_unique($actions));
    }

    // ------------------------------------------------------------------ day reconstruction (spec 102)

    /**
     * Merges already-fetched timeline items chronologically in the shift timezone.
     *
     * @param  array<int,array<string,mixed>>  $events  each: {at: ISO string|CarbonImmutable, label: string, kind?: string, detail?: string, ...extra}
     * @return array<int,array<string,mixed>> {at, local_date, local_time, day_label, display, kind, label, detail, meta}
     */
    public static function dayReconstruction(array $events, string $timezone, ?string $attendanceDate = null): array
    {
        $rows = [];
        foreach (array_values($events) as $i => $e) {
            $at = $e['at'] instanceof CarbonImmutable ? $e['at'] : CarbonImmutable::parse((string) $e['at']);
            $local = $at->setTimezone($timezone);
            $date = $local->toDateString();
            $dayLabel = '';
            if ($attendanceDate !== null) {
                $diff = intdiv(strtotime($date.' UTC') - strtotime($attendanceDate.' UTC'), 86400);
                $dayLabel = match (true) {
                    $diff === 0 => '', $diff === 1 => 'Next day', $diff === -1 => 'Previous day', default => $date,
                };
            }
            $meta = array_diff_key($e, array_flip(['at', 'label', 'kind', 'detail']));
            $rows[] = [
                'ts' => $at->getTimestamp(), 'i' => $i,
                'row' => [
                    'at' => $at->utc()->toIso8601String(), 'local_date' => $date, 'local_time' => $local->format('H:i'), 'zone' => $local->format('T'), 'day_label' => $dayLabel,
                    'display' => trim($dayLabel.' '.$local->format('H:i')), 'kind' => $e['kind'] ?? 'event', 'label' => (string) $e['label'], 'detail' => $e['detail'] ?? null, 'meta' => $meta,
                ],
            ];
        }
        usort($rows, fn ($a, $b) => $a['ts'] <=> $b['ts'] ?: $a['i'] <=> $b['i']);

        return array_map(fn ($r) => $r['row'], $rows);
    }

    // ------------------------------------------------------------------ formatting helpers (pure)

    public static function duration(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $m.'m';
        }

        return $m === 0 ? $h.'h' : $h.'h '.$m.'m';
    }

    private static function time(string $iso, string $tz): string
    {
        return CarbonImmutable::parse($iso)->setTimezone($tz)->format('H:i');
    }

    private static function abbr(string $iso, string $tz): string
    {
        return CarbonImmutable::parse($iso)->setTimezone($tz)->format('T');
    }

    private static function graceText(string $mode): string
    {
        return $mode === 'from_start' ? 'counted from shift start' : 'counted beyond grace';
    }

    private static function breakMethod(string $method): string
    {
        return match ($method) {
            'punched_breaks' => 'punched breaks', 'fixed_minutes' => 'fixed deduction', 'scheduled_unpaid_break_segments' => 'scheduled unpaid break', 'auto_deduct' => 'automatic deduction', default => $method,
        };
    }

    private static function step(array $steps, string $name): ?array
    {
        foreach ($steps as $s) {
            if ($s['step'] === $name) {
                return $s;
            }
        }

        return null;
    }
}
