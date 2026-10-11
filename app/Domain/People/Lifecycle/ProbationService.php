<?php

namespace App\Domain\People\Lifecycle;

use App\Domain\Access\Classification;
use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\StatusService;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;

/** Probation confirm / extend by HR or the line manager (never for oneself). No performance review logic. */
final class ProbationService
{
    public function __construct(private StatusService $status, private DomainEvents $events, private TaskService $tasks, private DataScope $scope) {}

    public function canAct(User $user, Employee $employee): bool
    {
        if ($this->scope->isSelf($user, $employee)) {
            return false;
        }

        return ($user->allows('people.manage') && $this->scope->canAccess($user, $employee, 'people.manage')) || $this->scope->isManagerOf($user, $employee);
    }

    public function confirm(User $actor, Employee $employee, ?string $date, ?string $comments): Employee
    {
        abort_unless($this->canAct($actor, $employee), 403, 'You cannot confirm this probation.');

        return DB::transaction(function () use ($employee, $date, $comments) {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_if($employee->confirmation_date, 422, 'This employee is already confirmed.');
            abort_unless($employee->status === 'probation', 422, 'Only an employee on probation can be confirmed.');
            $date ??= TenantClock::today($employee->timezone);
            $start = ($employee->probation_start ?? $employee->joined_on)->toDateString();
            abort_if($date < $start, 422, 'The confirmation date cannot be before probation started.');
            $employee->confirmation_date = $date;
            $employee->save();
            $employee = $this->status->transition($employee, 'active', ['reason_code' => 'probation_confirmed', 'reason' => $comments, 'effective_date' => $date]);
            $this->events->record('ProbationConfirmed', ['employee' => $employee, 'subject' => ['employee', $employee->id], 'effective_date' => $date, 'classification' => Classification::MANAGER_SENSITIVE,
                'summary' => 'Probation confirmed on '.$date.'.', 'payload' => ['confirmation_date' => $date, 'extensions' => $employee->probation_extensions]]);
            Audit::record('probation_confirmed', 'people', 'employee:'.$employee->uuid, ['confirmation_date' => $date]);
            $this->closeTasks($employee);

            return $employee;
        });
    }

    public function extend(User $actor, Employee $employee, string $newEnd, string $reason): Employee
    {
        abort_unless($this->canAct($actor, $employee), 403, 'You cannot extend this probation.');

        return DB::transaction(function () use ($employee, $newEnd, $reason) {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_unless($employee->status === 'probation' && ! $employee->confirmation_date, 422, 'Only an employee on probation can have it extended.');
            $old = $employee->probation_end?->toDateString();
            abort_if($old && $newEnd <= $old, 422, 'The new end date must be after the current one ('.$old.').');
            abort_if($newEnd <= TenantClock::today($employee->timezone), 422, 'The new end date must be in the future.');
            $employee->probation_end = $newEnd;
            $employee->probation_extensions = $employee->probation_extensions + 1;
            $employee->save();
            $this->events->record('ProbationExtended', ['employee' => $employee, 'subject' => ['employee', $employee->id], 'effective_date' => $newEnd, 'classification' => Classification::MANAGER_SENSITIVE,
                'summary' => 'Probation extended to '.$newEnd.'.', 'payload' => ['from' => $old, 'to' => $newEnd, 'extensions' => $employee->probation_extensions, 'reason' => $reason]]);
            Audit::record('probation_extended', 'people', 'employee:'.$employee->uuid, ['from' => $old, 'to' => $newEnd]);
            $this->closeTasks($employee);

            return $employee;
        });
    }

    /** Completes the My Work tasks the reminder job opened for this employee's probation. */
    private function closeTasks(Employee $employee): void
    {
        foreach (WorkItem::query()->where('source_module', 'people')->where('source_entity', 'like', 'probation:'.$employee->uuid.':%')->where('status', 'open')->pluck('source_entity')->unique() as $entity) {
            $this->tasks->completeBySource('people', $entity);
        }
    }
}
