<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Engine\CalculationResult;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\Attendance\Models\AttendanceException;
use App\Domain\Attendance\Models\AttendanceTrace;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Attendance\Support\ExceptionType;
use App\Domain\Attendance\Support\Severity;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;

/**
 * Persists one calculation: upserts the attendance day (unique tenant, employee, date), appends an immutable trace ONLY when
 * something material changed (result, input hash or engine version), diffs exceptions by fingerprint and records domain events.
 * Must run inside the per-employee transaction of DayProcessor.
 */
final class ResultPersister
{
    public function __construct(private readonly ExceptionPrioritizer $prioritizer, private readonly DomainEvents $events) {}

    /** @return array{day:AttendanceDay,changed:bool,created:bool,exceptions_created:int,exceptions_resolved:int,previous_status:?string} */
    public function persist(Employee $employee, string $date, BuiltInput $built, CalculationResult $result, string $trigger, ?int $actorId = null): array
    {
        $now = Utc::str();
        $existing = AttendanceDay::query()->where('employee_id', $employee->id)->where('attendance_date', $date)->lockForUpdate()->first();
        $previousTrace = $existing ? AttendanceTrace::query()->where('day_id', $existing->id)->orderByDesc('calculation_version')->first() : null;

        [$resultArray, $steps, $explanation, $status, $confidence] = $this->applyAdjustments($result, $built);
        $engineVersion = Engine::version();
        $changed = ! $existing || ! $previousTrace
            || $previousTrace->engine_version !== $engineVersion
            || ($previousTrace->inputs['input_hash'] ?? null) !== $built->hash
            || Canonical::json($previousTrace->result) !== Canonical::json($resultArray);

        $schedule = $built->schedule;
        $version = $built->policy['version'] ?? null;
        $attributes = [
            'shift_id' => $schedule->shiftId, 'policy_version_id' => $version?->id, 'holiday_calendar_id' => $schedule->holidayCalendarId, 'work_week_id' => $schedule->workWeekId,
            'status' => $status, 'scheduled_start' => $schedule->start ? Utc::str($schedule->start) : null, 'scheduled_end' => $schedule->end ? Utc::str($schedule->end) : null,
            'first_in' => $result->firstIn ? Utc::str($result->firstIn) : null, 'last_out' => $result->lastOut ? Utc::str($result->lastOut) : null,
            'required_minutes' => $result->requiredMinutes, 'gross_minutes' => $result->grossMinutes, 'break_minutes' => $result->breakMinutes, 'net_minutes' => $result->netMinutes,
            'late_minutes' => $result->lateMinutes, 'early_exit_minutes' => $result->earlyExitMinutes, 'overtime_minutes' => $result->overtimeMinutes,
            'approved_overtime_minutes' => $built->input->approvedOvertimeMinutes, 'work_mode' => $result->workMode, 'missing_punch' => $result->missingPunch,
            'confidence' => $confidence, 'engine_version' => $engineVersion,
        ];

        $day = $existing ?? new AttendanceDay(['employee_id' => $employee->id, 'attendance_date' => $date, 'finalization' => 'open', 'calculation_version' => 1, 'open_exceptions' => 0]);
        $previousStatus = $existing?->status;
        if ($changed) {
            $day->fill($attributes);
            if ($existing) {
                $day->calculation_version = $existing->calculation_version + 1;
                if ($existing->finalization === 'approved') {
                    $day->finalization = 'open'; // the approval was for the previous result
                }
            }
        }
        $day->calculated_at = $now;
        if (! $day->exists) {
            $day->created_at = $now;
        }
        $day->updated_at = $now;
        $day->save();

        if ($changed) {
            AttendanceTrace::create(['day_id' => $day->id, 'calculation_version' => $day->calculation_version, 'engine_version' => $engineVersion, 'trigger' => substr($trigger, 0, 24),
                'inputs' => $built->snapshot + ['trigger' => $trigger], 'steps' => $steps, 'result' => $resultArray, 'explanation' => json_encode($explanation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'created_at' => $now, 'updated_at' => $now]);
        }

        [$created, $resolved] = $this->diffExceptions($employee, $date, $day, $result, $built, $now, $actorId);
        $open = AttendanceException::query()->where('employee_id', $employee->id)->where('attendance_date', $date)->whereIn('status', AttendanceException::OPEN_STATES)->count();
        if ($day->open_exceptions !== $open) {
            $day->open_exceptions = $open;
            $day->save();
        }

        if ($changed && ! ($date >= TenantClock::today() && $status === 'pending')) {
            $this->events->record('AttendanceCalculated', ['employee' => $employee, 'subject' => ['attendance_day', $day->id], 'actor_id' => $actorId, 'effective_date' => $date,
                'summary' => 'Attendance calculated for '.$date.': '.$status, 'payload' => ['date' => $date, 'status' => $status, 'previous_status' => $previousStatus, 'calculation_version' => $day->calculation_version,
                    'policy_version_id' => $version?->id, 'engine_version' => $engineVersion, 'trigger' => $trigger, 'day_uuid' => $day->uuid]]);
        }

        return ['day' => $day, 'changed' => $changed, 'created' => ! $existing, 'exceptions_created' => $created, 'exceptions_resolved' => $resolved, 'previous_status' => $previousStatus];
    }

    /** @return array{0:array,1:array,2:array,3:string,4:string} result array, steps, explanation, status, confidence */
    private function applyAdjustments(CalculationResult $result, BuiltInput $built): array
    {
        $array = $result->toArray();
        $steps = $result->steps;
        $explanation = $result->explanation;
        $status = $result->status;
        $confidence = $result->confidence;
        foreach ($built->snapshot['voided_punch_ids'] ?? [] as $id) {
            $steps[] = ['type' => 'adjustment', 'rule' => 'void_punch', 'details' => ['punch' => $id, 'note' => 'A manual adjustment removed this raw punch from the calculation; the raw punch itself is unchanged.']];
        }
        foreach ($built->snapshot['punches'] ?? [] as $p) {
            if (($p['origin'] ?? 'raw') !== 'raw') {
                $steps[] = ['type' => 'virtual_punch', 'rule' => $p['origin'], 'details' => ['punch' => $p['id'], 'at' => $p['at'], 'direction' => $p['direction']]];
            }
        }
        if ($o = $built->statusOverride) {
            $steps[] = ['type' => 'adjustment', 'rule' => 'status_override', 'details' => ['calculated_status' => $status, 'status' => $o['status'], 'adjustment_id' => $o['adjustment_id'], 'note' => $o['note'], 'checker_approved' => $o['checker_approved']]];
            $array['calculated_status'] = $status;
            $array['status_override'] = ['status' => $o['status'], 'adjustment_id' => $o['adjustment_id'], 'note' => $o['note']];
            $status = $o['status'];
            $array['status'] = $status;
            $confidence = $o['checker_approved'] ? 'confirmed' : 'review_required';
            $array['confidence'] = $confidence;
            $explanation['status_override'] = ['calculated_status' => $result->status, 'status' => $status, 'note' => $o['note'], 'approved' => $o['checker_approved']];
        }
        if (! empty($built->issues)) {
            $array['issues'] = $built->issues;
        }

        return [$array, $steps, $explanation, $status, $confidence];
    }

    /** @return array{0:int,1:int} created, auto-resolved */
    private function diffExceptions(Employee $employee, string $date, AttendanceDay $day, CalculationResult $result, BuiltInput $built, string $now, ?int $actorId): array
    {
        $detected = [];
        foreach ($result->exceptions as $e) {
            $detected[] = ['type' => $e['type'], 'severity' => $e['severity'] ?? null, 'rule' => $e['rule'] ?? $e['type'], 'details' => $e['details'] ?? []];
        }
        $types = array_column($detected, 'type');
        if (in_array('missing_policy', $built->issues, true) && ! in_array(ExceptionType::NO_POLICY, $types, true)) {
            $detected[] = ['type' => ExceptionType::NO_POLICY, 'severity' => Severity::ACTION_REQUIRED, 'rule' => 'policy.resolution', 'details' => ['considered' => count($built->considered)]];
        }
        if (in_array('missing_shift', $built->issues, true) && ! in_array(ExceptionType::NO_SHIFT, $types, true)) {
            $detected[] = ['type' => ExceptionType::NO_SHIFT, 'severity' => Severity::ACTION_REQUIRED, 'rule' => 'schedule.resolution', 'details' => ['notes' => $built->schedule->notes]];
        }

        $existing = AttendanceException::query()->where('employee_id', $employee->id)->where('attendance_date', $date)->get()->keyBy(fn ($e) => $e->type.'|'.$e->fingerprint);
        $seen = [];
        $created = 0;
        $context = ['employee_id' => $employee->id, 'date' => $date, 'finalization' => $day->finalization];
        foreach ($detected as $d) {
            $fingerprint = substr((string) ($d['details']['fingerprint'] ?? $d['details']['punch_id'] ?? $d['rule']), 0, 120);
            $key = $d['type'].'|'.$fingerprint;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $p = $this->prioritizer->prioritize($d['type'], $d, $context);
            $details = $d['details'] + ['title' => $p['title'], 'priority_reason' => $p['reason']];
            $row = $existing->get($key);
            if (! $row) {
                $row = AttendanceException::create(['employee_id' => $employee->id, 'attendance_date' => $date, 'day_id' => $day->id, 'type' => $d['type'], 'severity' => $p['severity'], 'status' => 'open',
                    'source_rule' => substr($d['rule'], 0, 80), 'fingerprint' => $fingerprint, 'detected_at' => $now, 'details' => $details, 'created_at' => $now, 'updated_at' => $now]);
                $created++;
                $this->events->record('AttendanceExceptionDetected', ['employee' => $employee, 'subject' => ['attendance_exception', $row->id], 'actor_id' => $actorId, 'effective_date' => $date,
                    'summary' => $p['title'].' on '.$date, 'payload' => ['exception_uuid' => $row->uuid, 'type' => $d['type'], 'severity' => $p['severity'], 'date' => $date, 'rule' => $d['rule']]]);

                continue;
            }
            if ($row->status === 'dismissed' || $row->status === 'resolved') {
                continue; // human decisions are never reopened by a recalculation
            }
            if ($row->status === 'auto_resolved') {
                $row->status = 'open';
                $row->resolved_at = null;
                $row->resolution = null;
            }
            $row->fill(['severity' => $p['severity'], 'details' => $details, 'source_rule' => substr($d['rule'], 0, 80), 'day_id' => $day->id, 'updated_at' => $now])->save();
        }
        $resolved = 0;
        foreach ($existing as $key => $row) {
            if (! isset($seen[$key]) && in_array($row->status, AttendanceException::OPEN_STATES, true)) {
                $row->forceFill(['status' => 'auto_resolved', 'resolved_at' => $now, 'resolution' => 'No longer detected after recalculation.', 'updated_at' => $now])->save();
                $resolved++;
            }
        }

        return [$created, $resolved];
    }
}
