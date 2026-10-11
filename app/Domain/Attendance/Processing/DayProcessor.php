<?php

namespace App\Domain\Attendance\Processing;

use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Models\AttendanceDay;
use App\Domain\People\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Processes ONE employee-date: serialized per employee (row lock on employees), idempotent, retried on deadlock.
 * Outcomes: processed | unchanged | skipped_locked | skipped_not_employed | not_found. Failures throw ProcessingFailed.
 */
final class DayProcessor
{
    public const ATTEMPTS = 3;

    public function __construct(private readonly InputBuilder $builder, private readonly ResultPersister $persister, private readonly LockGuard $locks) {}

    /** @return array{outcome:string,day_id:?int,changed:bool,issues:array<int,string>} */
    public function process(int $employeeId, string $date, string $trigger, ?int $actorId = null, bool $allowLocked = false): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => $this->run($employeeId, $date, $trigger, $actorId, $allowLocked));
            } catch (QueryException $e) {
                if ($attempt >= self::ATTEMPTS || ! $this->retryable($e)) {
                    throw $e;
                }
                Log::warning('attendance.process_retry', ['employee_id' => $employeeId, 'date' => $date, 'attempt' => $attempt, 'error' => $e->getMessage()]);
                usleep(random_int(20, 80) * 1000);
            }
        }
    }

    private function run(int $employeeId, string $date, string $trigger, ?int $actorId, bool $allowLocked): array
    {
        $employee = Employee::query()->whereKey($employeeId)->lockForUpdate()->first(); // serializes every recalculation of this employee
        if (! $employee) {
            return ['outcome' => 'not_found', 'day_id' => null, 'changed' => false, 'issues' => []];
        }
        if (! self::employedOn($employee, $date)) {
            return ['outcome' => 'skipped_not_employed', 'day_id' => null, 'changed' => false, 'issues' => []];
        }
        $day = AttendanceDay::query()->where('employee_id', $employeeId)->where('attendance_date', $date)->first();
        if (! $allowLocked && (($day && $day->finalization === 'locked') || $this->locks->isLocked($employeeId, $date))) {
            return ['outcome' => 'skipped_locked', 'day_id' => $day?->id, 'changed' => false, 'issues' => []];
        }
        try {
            $built = $this->builder->build($employee, $date);
            $result = Engine::calculator()->calculate($built->input);
        } catch (QueryException $e) {
            throw $e;
        } catch (ProcessingFailed $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('attendance.calculation_failed', ['employee_id' => $employeeId, 'date' => $date, 'trigger' => $trigger, 'exception' => $e::class, 'error' => $e->getMessage()]);
            throw new ProcessingFailed(ProcessingFailed::CALCULATION, 'We could not calculate attendance for this day. Our team has been notified.', $e);
        }
        $saved = $this->persister->persist($employee, $date, $built, $result, $trigger, $actorId);

        return ['outcome' => $saved['changed'] ? 'processed' : 'unchanged', 'day_id' => $saved['day']->id, 'changed' => $saved['changed'], 'issues' => $built->issues];
    }

    /** Employed (and not archived / preboarding) on the attendance date. Historical days of separated employees remain processable. */
    public static function employedOn(Employee $employee, string $date): bool
    {
        if (in_array($employee->status, ['preboarding'], true) || $employee->archived_at !== null) {
            return false;
        }
        $joined = $employee->joined_on instanceof \DateTimeInterface ? $employee->joined_on->format('Y-m-d') : (string) $employee->joined_on;
        $separated = $employee->separated_on instanceof \DateTimeInterface ? $employee->separated_on->format('Y-m-d') : $employee->separated_on;

        return $joined <= $date && ($separated === null || $separated >= $date);
    }

    private function retryable(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return in_array((string) $e->getCode(), ['40001', '1213', '1205', '23000', '23505'], true) || str_contains($message, 'deadlock') || str_contains($message, 'database is locked') || str_contains($message, 'lock wait timeout');
    }
}
