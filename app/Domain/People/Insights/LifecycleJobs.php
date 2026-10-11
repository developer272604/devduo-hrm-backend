<?php

namespace App\Domain\People\Insights;

use App\Domain\Access\Classification;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\ReportingService;
use App\Domain\People\Services\StatusService;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled People jobs, run inside one tenant context (the artisan commands wrap them in TenantRunner). All are idempotent.
 */
final class LifecycleJobs
{
    /** Reminder bands: days left (inclusive upper bound, exclusive lower bound of the next band). */
    public const WINDOWS = [14, 3];

    public function __construct(private StatusService $status, private DomainEvents $events, private TaskService $tasks, private NotificationService $notifications, private ReportingService $reporting) {}

    /** @return array{activated:int,separated:int,failed:int} */
    public function advanceLifecycle(): array
    {
        $result = ['activated' => 0, 'separated' => 0, 'failed' => 0];
        foreach (Employee::query()->where('status', 'preboarding')->orderBy('id')->get() as $employee) {
            if ($employee->joined_on->toDateString() > TenantClock::today($employee->timezone)) {
                continue;
            }
            $this->attempt($result, 'activated', fn () => $this->status->transition($employee, $employee->probation_applicable ? 'probation' : 'active',
                ['reason_code' => 'lifecycle_auto', 'reason' => 'Joining date reached', 'effective_date' => $employee->joined_on->toDateString()]));
        }
        foreach (Employee::query()->where('status', 'notice_period')->whereNotNull('separated_on')->orderBy('id')->get() as $employee) {
            if ($employee->separated_on->toDateString() >= TenantClock::today($employee->timezone)) {
                continue; // the last working day is still today or in the future
            }
            $this->attempt($result, 'separated', fn () => $this->status->transition($employee, 'separated',
                ['reason_code' => 'lifecycle_auto', 'reason' => 'Last working date has passed', 'effective_date' => $employee->separated_on->addDay()->toDateString()]));
        }

        return $result;
    }

    private function attempt(array &$result, string $key, callable $do): void
    {
        try {
            $do();
            $result[$key]++;
        } catch (\Throwable $e) {
            $result['failed']++;
        }
    }

    /** @return array{reminded:int,closed:int} */
    public function probationReminders(): array
    {
        $today = CarbonImmutable::parse(TenantClock::today());
        $reminded = 0;
        $employees = Employee::query()->with('person')->where('status', 'probation')->whereNull('confirmation_date')->whereNotNull('probation_end')
            ->where('probation_end', '<=', $today->addDays(max(self::WINDOWS))->toDateString())->where('probation_end', '>=', $today->toDateString())->orderBy('id')->get();
        foreach ($employees as $employee) {
            $left = (int) $today->diffInDays($employee->probation_end, false);
            $window = $left <= min(self::WINDOWS) ? min(self::WINDOWS) : max(self::WINDOWS);
            $end = $employee->probation_end->toDateString();
            $already = DB::table('domain_events')->where('tenant_id', $employee->tenant_id)->where('employee_id', $employee->id)->where('type', 'ProbationDue')
                ->where('subject_type', 'probation_window')->where('subject_id', $window)->where('effective_date', $end)->exists();
            if ($already) {
                continue;
            }
            $this->events->record('ProbationDue', ['employee' => $employee, 'subject' => ['probation_window', $window], 'actor_id' => null, 'classification' => Classification::MANAGER_SENSITIVE, 'effective_date' => $end,
                'summary' => 'Probation ends in '.$left.' day'.($left === 1 ? '' : 's').' (on '.$end.').', 'payload' => ['window' => $window, 'days_left' => $left, 'probation_end' => $end]]);
            $this->notify($employee, $window, $left, $end);
            $reminded++;
        }

        return ['reminded' => $reminded, 'closed' => $this->closeStaleTasks()];
    }

    private function notify(Employee $employee, int $window, int $left, string $end): void
    {
        $manager = $this->reporting->getManager($employee);
        $recipients = [];
        if ($manager && ($id = DB::table('people')->where('id', $manager->person_id)->value('user_id'))) {
            $recipients[] = (int) $id;
        }
        if (! $recipients || $window === min(self::WINDOWS)) { // HR is told at the last window, and whenever there is no line manager
            foreach ($this->hrUsers() as $id) {
                $recipients[] = $id;
            }
        }
        $name = $employee->person->display_name;
        foreach (array_unique($recipients) as $userId) {
            $this->tasks->createOnce(['assignee_id' => $userId, 'title' => 'Confirm or extend probation: '.$name, 'description' => 'Probation ends on '.$end.'.', 'source_module' => 'people',
                'source_entity' => 'probation:'.$employee->uuid.':'.$end, 'priority' => $window <= 3 ? 'high' : 'normal', 'due_date' => $end, 'action' => '/employees/'.$employee->uuid,
                'metadata' => ['employee_uuid' => $employee->uuid, 'probation_end' => $end]]);
            $this->notifications->send($userId, 'Probation review due', $name.'\'s probation ends in '.$left.' day'.($left === 1 ? '' : 's').' ('.$end.').', 'people', '/employees/'.$employee->uuid);
        }
    }

    /** Active users who can manage people across the whole company (HR). @return array<int,int> */
    private function hrUsers(): array
    {
        $tenant = app(\App\Domain\Tenancy\TenantContext::class)->id;

        return \App\Models\User::query()->where('tenant_id', $tenant)->where('active', true)->get()
            ->filter(fn ($u) => $u->allows('people.manage') && $u->hasTenantScope())->take(10)->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** Completes probation tasks of employees who are no longer in probation or whose probation date moved. */
    private function closeStaleTasks(): int
    {
        $closed = 0;
        foreach (WorkItem::query()->where('source_module', 'people')->where('source_entity', 'like', 'probation:%')->where('status', 'open')->get() as $task) {
            [, $uuid, $end] = array_pad(explode(':', $task->source_entity), 3, null);
            $employee = Employee::query()->where('uuid', $uuid)->first();
            if (! $employee || $employee->status !== 'probation' || $employee->confirmation_date || $employee->probation_end?->toDateString() !== $end) {
                $closed += $this->tasks->completeBySource('people', $task->source_entity);
            }
        }

        return $closed;
    }
}
