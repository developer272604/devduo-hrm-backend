<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Contracts\LeaveDataProvider;
use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Attendance\Engine\CalculationInput;
use App\Domain\Attendance\Engine\DaySchedule;
use App\Domain\Attendance\Engine\Punch;
use App\Domain\Attendance\Policies\PolicyResolver;
use App\Domain\Attendance\Scheduling\OrgContext;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Attendance\Support\PolicyRules;
use App\Domain\Attendance\Support\TimeZones;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loads everything for ONE employee-date and builds the pure CalculationInput. READ-ONLY: the simulator reuses it
 * against current raw data, so it must never write.
 *
 * Raw punches are never modified; approved regularizations and applied/approved adjustments add VIRTUAL punches that the
 * calculator sees next to the raw ones (origin tells where each came from). A status_override adjustment is applied
 * after calculation by the persister.
 */
final class InputBuilder
{
    /** @var null|\Closure():CarbonImmutable */
    private $clock;

    public function __construct(private readonly ScheduleResolver $schedules, private readonly PolicyResolver $policies, private readonly LeaveDataProvider $leave, ?\Closure $clock = null)
    {
        $this->clock = $clock;
    }

    /**
     * @param  array<string,mixed>|null  $rulesOverride  STORED policy rules to use instead of the resolved version's (simulator)
     */
    public function build(Employee $employee, string $date, ?OrgContext $org = null, ?array $rulesOverride = null, ?CarbonImmutable $now = null): BuiltInput
    {
        $tenant = app(TenantContext::class)->id;
        $org ??= OrgContext::load([$employee->id], $date, $date);
        $now ??= $this->clock ? ($this->clock)() : Utc::now();
        $schedule = $this->schedules->resolve($employee->id, $date);
        $trace = $this->policies->resolveWithTrace($employee->id, $date, $org);
        $match = $trace['match'];
        $version = $match['version'] ?? null;
        $stored = $rulesOverride ?? ($version?->rules ?? []);
        $rules = PolicyRules::merge($stored, $schedule->shiftRules);
        $issues = [];
        if (! $match && $rulesOverride === null) {
            $issues[] = 'missing_policy';
        }
        if ($schedule->kind === 'not_scheduled' && $schedule->source === 'none' && in_array($employee->status, Employee::WORKING, true)) {
            $issues[] = 'missing_shift';
        }

        // ---- punches ----------------------------------------------------------------------
        [$from, $to] = $this->window($schedule, $date);
        $voided = [];
        $virtual = [];
        $statusOverride = null;
        $adjustmentIds = [];
        $regularizationIds = [];
        foreach (DB::table('attendance_adjustments')->where('tenant_id', $tenant)->where('employee_id', $employee->id)->where('attendance_date', $date)->whereIn('status', ['applied', 'approved'])->orderBy('id')->get() as $adj) {
            $after = json_decode($adj->after ?? '{}', true) ?: [];
            $adjustmentIds[] = $adj->id;
            if ($adj->type === 'add_punch' && ! empty($after['at'])) {
                $virtual[] = new Punch('adjustment:'.$adj->id, CarbonImmutable::parse($after['at'])->utc(), strtoupper($after['direction'] ?? 'UNKNOWN'), 'admin', 'adjustment');
            } elseif ($adj->type === 'void_punch' && ! empty($after['event_id'])) {
                $voided[] = 'event:'.$after['event_id'];
            } elseif ($adj->type === 'status_override' && ! empty($after['status'])) {
                $statusOverride = ['adjustment_id' => $adj->id, 'status' => $after['status'], 'note' => $after['note'] ?? null,
                    'checker_approved' => $adj->checker_id !== null || $adj->status === 'approved', 'requires_checker' => (bool) $adj->requires_checker];
            }
        }
        foreach (DB::table('attendance_regularizations')->where('tenant_id', $tenant)->where('employee_id', $employee->id)->where('attendance_date', $date)->where('status', 'approved')->orderBy('id')->get() as $reg) {
            $regularizationIds[] = $reg->id;
            if ($reg->requested_in) {
                $virtual[] = new Punch('regularization:'.$reg->id.':in', Utc::parse($reg->requested_in), 'IN', 'admin', 'regularization');
            }
            if ($reg->requested_out) {
                $virtual[] = new Punch('regularization:'.$reg->id.':out', Utc::parse($reg->requested_out), 'OUT', 'admin', 'regularization');
            }
        }
        $punches = [];
        foreach (DB::table('attendance_events')->where('tenant_id', $tenant)->where('employee_id', $employee->id)->whereNull('voided_at')
            ->where('punched_at', '>=', Utc::str($from))->where('punched_at', '<=', Utc::str($to))->orderBy('punched_at')->orderBy('id')->get() as $event) {
            $id = 'event:'.$event->id;
            if (in_array($id, $voided, true)) {
                continue;
            }
            $punches[] = new Punch($id, Utc::parse($event->punched_at), $event->direction, $event->source, 'raw', $event->geofence_status, $event->verification_status);
        }
        $punches = array_merge($punches, $virtual);

        // ---- approvals ---------------------------------------------------------------------
        $onDuty = [];
        $remote = false;
        foreach (DB::table('on_duty_requests')->where('tenant_id', $tenant)->where('employee_id', $employee->id)->where('status', 'approved')->where('start_date', '<=', $date)->where('end_date', '>=', $date)->orderBy('id')->get() as $od) {
            $onDuty[] = ['start' => Utc::parse($od->starts_at), 'end' => Utc::parse($od->ends_at), 'type' => $od->type, 'id' => 'on_duty:'.$od->id];
            $remote = $remote || $od->type === 'remote_work';
        }
        $assignment = $org->assignmentOn($employee->id, $date);
        $remote = $remote || (($assignment['work_mode'] ?? 'onsite') === 'remote');
        $approvedOvertime = (int) DB::table('overtime_requests')->where('tenant_id', $tenant)->where('employee_id', $employee->id)->where('work_date', $date)->whereIn('status', ['approved', 'partially_approved'])->sum('approved_minutes');
        $eligibleTypes = $rules['overtime']['eligible_employee_type_ids'] ?? null;
        $typeEligible = ! is_array($eligibleTypes) || in_array((int) ($assignment['employee_type_id'] ?? 0), array_map('intval', $eligibleTypes), true);

        $leave = $this->leave->coverage($employee->id, $date);
        $monthToDate = $this->monthToDate($employee->id, $date);
        $previousEnd = $this->previousDayEnd($employee->id, $date);
        $compliance = $this->compliance($employee, $assignment);
        $label = $version ? $version->label() : null;

        $input = new CalculationInput($schedule, $rules, $punches, $now, $onDuty, $leave, $monthToDate, $approvedOvertime, $remote, $typeEligible, $label, $version?->id, $previousEnd, $compliance);

        $snapshot = [
            'policy' => $version ? ['policy_id' => $version->policy_id, 'version_id' => $version->id, 'version' => $version->version, 'label' => $label, 'rules_hash' => $version->rules_hash,
                'assignment_id' => $match['assignment']->id, 'matched_by' => $match['assignment']->target_type] : null,
            'schedule' => $schedule->toArray(), 'rules' => $rules, 'punches' => array_map(fn (Punch $p) => $p->toArray(), $punches), 'voided_punch_ids' => $voided,
            'on_duty' => array_map(fn ($o) => ['start' => $o['start']->toIso8601String(), 'end' => $o['end']->toIso8601String(), 'type' => $o['type'], 'id' => $o['id']], $onDuty),
            'approved_overtime_minutes' => $approvedOvertime, 'leave' => $leave, 'month_to_date' => $monthToDate, 'previous_day_end' => $previousEnd, 'compliance' => $compliance,
            'remote_approved' => $remote, 'overtime_type_eligible' => $typeEligible, 'status_override' => $statusOverride, 'adjustment_ids' => $adjustmentIds, 'regularization_ids' => $regularizationIds,
            'rules_override' => $rulesOverride !== null,
        ];
        $hash = Canonical::hash($snapshot);

        return new BuiltInput($input, $schedule, $match, $trace['considered'], $snapshot + ['input_hash' => $hash], $hash, $statusOverride, $issues);
    }

    /** UTC window in which punches belong to this day's schedule. */
    public function window(DaySchedule $schedule, string $date): array
    {
        $tz = TimeZones::isValid($schedule->timezone) ? $schedule->timezone : 'UTC';
        $start = $schedule->windowStart ?? TimeZones::localToUtc($date, '00:00', $tz);
        $end = $schedule->windowEnd ?? TimeZones::localToUtc($date, '00:00', $tz, 1)->subSecond();

        return [$start->utc(), $end->utc()];
    }

    /** @return array{late_count:int,early_count:int,late_minutes:int} occurrences earlier in the same month (days must be persisted ascending). */
    private function monthToDate(int $employeeId, string $date): array
    {
        $first = substr($date, 0, 8).'01';
        $rows = DB::table('attendance_days')->where('tenant_id', app(TenantContext::class)->id)->where('employee_id', $employeeId)
            ->where('attendance_date', '>=', $first)->where('attendance_date', '<', $date)->get(['late_minutes', 'early_exit_minutes']);

        return ['late_count' => $rows->where('late_minutes', '>', 0)->count(), 'early_count' => $rows->where('early_exit_minutes', '>', 0)->count(), 'late_minutes' => (int) $rows->sum('late_minutes')];
    }

    private function previousDayEnd(int $employeeId, string $date): ?string
    {
        $row = DB::table('attendance_days')->where('tenant_id', app(TenantContext::class)->id)->where('employee_id', $employeeId)->where('attendance_date', '<', $date)
            ->where('attendance_date', '>=', CarbonImmutable::parse($date)->subDays(2)->toDateString())->where(fn ($q) => $q->whereNotNull('last_out')->orWhereNotNull('scheduled_end'))
            ->orderByDesc('attendance_date')->first(['last_out', 'scheduled_end']);
        $end = $row ? ($row->last_out ?? $row->scheduled_end) : null;

        return $end ? Utc::parse($end)->format('Y-m-d\TH:i:s\Z') : null;
    }

    /** Merged compliance rules (rest / max hours) when the scheduling workstream provides them. */
    private function compliance(Employee $employee, ?array $assignment): array
    {
        $class = 'App\\Domain\\Attendance\\Scheduling\\ComplianceRules';
        if (! class_exists($class)) {
            return [];
        }
        try {
            $instance = app($class);
            foreach (['forEmployee', 'rulesFor', 'for', 'resolve'] as $method) {
                if (method_exists($instance, $method)) {
                    $country = $employee->country;

                    return (array) ($method === 'forEmployee' ? $instance->forEmployee($employee) : $instance->{$method}($country));
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('attendance.compliance_unavailable', ['employee_id' => $employee->id, 'error' => $e->getMessage()]);
        }

        return [];
    }
}
