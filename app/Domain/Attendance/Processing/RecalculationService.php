<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Models\ProcessingRun;
use App\Domain\Attendance\Services\Utc;
use App\Domain\Notifications\NotificationService;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\Attendance\ProcessAttendanceRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Implements AttendanceRecalculator. A day is processed inline; a scope creates an attendance_processing_runs record
 * (uuid). Up to INLINE_LIMIT employee-days run inline, larger scopes are queued (ProcessAttendanceRun) in chunks of 100 employees,
 * dates ascending per employee so month-to-date counters see earlier days first.
 */
final class RecalculationService implements AttendanceRecalculator
{
    public const INLINE_LIMIT = 200;

    public const TRIGGERS = ['punch', 'manual', 'scheduled', 'nightly', 'device_sync', 'regularization', 'recalculation', 'import'];

    public const SLOW_MS = 60000;

    public function __construct(private readonly DayProcessor $days, private readonly ScopeExpander $scopes) {}

    public function recalculateDay(int $employeeId, string $date, string $trigger, ?int $requestedBy = null): ?int
    {
        try {
            $outcome = $this->days->process($employeeId, $date, $trigger, $requestedBy);
        } catch (\Throwable $e) {
            Log::error('attendance.recalculate_day_failed', ['employee_id' => $employeeId, 'date' => $date, 'trigger' => $trigger, 'exception' => $e::class, 'error' => $e->getMessage()]);

            return null;
        }

        return in_array($outcome['outcome'], ['processed', 'unchanged'], true) ? $outcome['day_id'] : null;
    }

    public function recalculate(array $scope, string $trigger, ?int $requestedBy = null, ?string $dedupeKey = null): string
    {
        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw new \InvalidArgumentException('Unknown processing trigger "'.$trigger.'".');
        }
        $scope = $this->scopes->normalize($scope);
        $dedupe = $dedupeKey ?? substr(sha1(Canonical::json([$scope, $trigger])), 0, 40);
        $active = ProcessingRun::query()->where('dedupe_key', $dedupe)->whereIn('status', ['queued', 'processing'])->first();
        if ($active) {
            return $active->uuid;
        }
        $count = $this->scopes->count($scope);
        $run = ProcessingRun::create(['trigger' => $trigger, 'scope' => $scope, 'status' => 'queued', 'dedupe_key' => $dedupe, 'total_days' => $count['employee_days'], 'requested_by' => $requestedBy,
            'created_at' => Utc::str(), 'updated_at' => Utc::str()]);
        if ($count['employee_days'] <= self::INLINE_LIMIT) {
            $this->execute($run);
        } else {
            ProcessAttendanceRun::dispatch(app(TenantContext::class)->id, $run->id);
        }

        return $run->uuid;
    }

    /** Runs (or re-runs: idempotent) a processing run to completion. */
    public function execute(ProcessingRun $run): ProcessingRun
    {
        $started = microtime(true);
        $run->forceFill(['status' => 'processing', 'started_at' => Utc::str(), 'finished_at' => null, 'processed_days' => 0, 'failed_days' => 0, 'skipped_locked' => 0, 'errors' => null])->save();
        $processed = $failed = $locked = 0;
        $failures = [];   // cause => [message, employees => [id => days]]
        $attention = [];  // issue => [employee id => days]
        foreach ($this->scopes->expand($run->scope) as $chunk) {
            foreach ($chunk as $employeeId => $dates) {
                foreach ($dates as $date) { // ascending: each day is committed before the next is built
                    try {
                        $o = $this->days->process($employeeId, $date, $run->trigger, $run->requested_by);
                    } catch (ProcessingFailed $e) {
                        $failed++;
                        $failures[$e->cause]['employees'][$employeeId] = ($failures[$e->cause]['employees'][$employeeId] ?? 0) + 1;

                        continue;
                    } catch (\Throwable $e) {
                        Log::error('attendance.run_day_failed', ['run' => $run->uuid, 'employee_id' => $employeeId, 'date' => $date, 'exception' => $e::class, 'error' => $e->getMessage()]);
                        $failed++;
                        $failures['unexpected_error']['employees'][$employeeId] = ($failures['unexpected_error']['employees'][$employeeId] ?? 0) + 1;

                        continue;
                    }
                    if ($o['outcome'] === 'skipped_locked') {
                        $locked++;
                    } elseif (in_array($o['outcome'], ['processed', 'unchanged'], true)) {
                        $processed++;
                        foreach ($o['issues'] as $issue) {
                            $attention[$issue][$employeeId] = ($attention[$issue][$employeeId] ?? 0) + 1;
                        }
                    }
                }
            }
            $run->forceFill(['processed_days' => $processed, 'failed_days' => $failed, 'skipped_locked' => $locked])->save();
        }
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $errors = $this->errors($failures, $attention, $locked, $durationMs);
        $status = $failed === 0 ? 'completed' : ($processed > 0 || $locked > 0 ? 'partial' : 'failed');
        $run->forceFill(['status' => $status, 'processed_days' => $processed, 'failed_days' => $failed, 'skipped_locked' => $locked, 'errors' => $errors ?: null, 'finished_at' => Utc::str(), 'updated_at' => Utc::str()])->save();
        if ($durationMs > self::SLOW_MS) {
            Log::warning('attendance.slow_run', ['run' => $run->uuid, 'duration_ms' => $durationMs, 'total_days' => $run->total_days]);
        }
        if ($failed > 0) {
            Log::warning('attendance.run_failures', ['run' => $run->uuid, 'status' => $status, 'failed_days' => $failed]);
        }

        return $run->fresh();
    }

    /** Marks a run failed after the queue gave up (job failed() handler). */
    public function fail(ProcessingRun $run, \Throwable $e): void
    {
        Log::error('attendance.run_failed', ['run' => $run->uuid, 'exception' => $e::class, 'error' => $e->getMessage()]);
        $run->forceFill(['status' => 'failed', 'finished_at' => Utc::str(), 'errors' => [['code' => 'run_failed', 'kind' => 'failure', 'message' => 'We could not finish processing attendance. Please try again; if it keeps happening, contact support.',
            'employee_count' => 0, 'day_count' => 0, 'employees' => []]]])->save();
        if ($run->requested_by) {
            try {
                app(NotificationService::class)->send($run->requested_by, 'Attendance processing did not finish', 'Please try again from Attendance operations.', 'attendance', '/attendance/processing');
            } catch (\Throwable) {
                // notification problems never hide the original failure
            }
        }
    }

    /** @return array<int,array<string,mixed>> friendly aggregated reasons, grouped by cause, at most 20 employees listed per cause */
    private function errors(array $failures, array $attention, int $locked, int $durationMs): array
    {
        $out = [];
        foreach ($failures as $cause => $info) {
            $employees = $info['employees'];
            $out[] = ['code' => $cause, 'kind' => 'failure', 'message' => $this->message($cause, count($employees)), 'employee_count' => count($employees), 'day_count' => array_sum($employees), 'employees' => $this->people(array_keys($employees))];
        }
        foreach ($attention as $issue => $employees) {
            $out[] = ['code' => $issue, 'kind' => 'attention', 'message' => $this->message($issue, count($employees)), 'employee_count' => count($employees), 'day_count' => array_sum($employees), 'employees' => $this->people(array_keys($employees))];
        }
        if ($locked > 0) {
            $out[] = ['code' => 'skipped_locked', 'kind' => 'info', 'message' => $locked.' '.($locked === 1 ? 'day was' : 'days were').' skipped because the attendance period is locked. Request an unlock to change it.',
                'employee_count' => 0, 'day_count' => $locked, 'employees' => []];
        }
        if ($durationMs > self::SLOW_MS) {
            $out[] = ['code' => 'slow_run', 'kind' => 'info', 'message' => 'This run took longer than usual ('.round($durationMs / 1000).' seconds).', 'employee_count' => 0, 'day_count' => 0, 'employees' => [], 'duration_ms' => $durationMs];
        }

        return $out;
    }

    private function message(string $code, int $n): string
    {
        $who = $n === 1 ? '1 employee' : $n.' employees';

        return match ($code) {
            'missing_shift' => "We couldn't process attendance for $who because their shift assignment is missing.",
            'missing_policy' => "We couldn't process attendance for $who because no attendance policy applies to them.",
            ProcessingFailed::CALCULATION => "We couldn't process attendance for $who because of an unexpected calculation problem. It has been logged for our team.",
            default => "We couldn't process attendance for $who because of an unexpected problem. It has been logged for our team.",
        };
    }

    /** @param array<int,int> $ids */
    private function people(array $ids): array
    {
        $rows = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', app(TenantContext::class)->id)
            ->whereIn('employees.id', array_slice($ids, 0, 20))->orderBy('employees.id')->get(['employees.uuid', 'employees.employee_number', 'people.display_name']);

        return $rows->map(fn ($r) => ['uuid' => $r->uuid, 'employee_number' => $r->employee_number, 'name' => $r->display_name])->all();
    }
}
