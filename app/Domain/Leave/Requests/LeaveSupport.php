<?php

namespace App\Domain\Leave\Requests;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Boot\RequestsBoot;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Shared plumbing for leave services: scope, My Work tasks, deduplicated notifications, private files, attendance coverage sync. */
final class LeaveSupport
{
    public const MAX_FILE_BYTES = 5 * 1024 * 1024;

    public function __construct(private TaskService $tasks, private NotificationService $notifications, private DataScope $scope, private DomainEvents $events) {}

    public function scope(): DataScope
    {
        return $this->scope;
    }

    /** Resolves an employee UUID the actor may act on; out-of-scope is reported as 404 (existence never disclosed). */
    public function employee(User $actor, string $uuid, string $permission, bool $allowSelf = true): Employee
    {
        $employee = Employee::query()->where('uuid', $uuid)->first();
        abort_unless($employee && (($allowSelf && $this->scope->isSelf($actor, $employee)) || $this->scope->canAccess($actor, $employee, $permission)), 404, 'That employee was not found.');

        return $employee;
    }

    public function ownEmployee(User $actor): Employee
    {
        $ids = $this->scope->ownEmployeeIds($actor);
        $employee = $ids ? Employee::query()->whereIn('id', $ids)->orderBy('id')->get()->first(fn ($e) => $e->isWorking()) : null;
        abort_unless($employee, 422, 'Your login is not linked to an active employment, so there is nothing to request leave for.');

        return $employee;
    }

    // ---- My Work ---------------------------------------------------------------------------------

    public function assignTasks(iterable $assignees, string $title, string $description, string $sourceEntity, string $action, ?string $dueDate = null, array $metadata = []): int
    {
        $created = 0;
        foreach ($assignees as $user) {
            $task = $this->tasks->createOnce(['assignee_id' => $user instanceof User ? $user->id : (int) $user, 'title' => $title, 'description' => $description, 'source_module' => 'leave',
                'source_entity' => $sourceEntity, 'priority' => 'normal', 'due_date' => $dueDate, 'action' => $action, 'metadata' => $metadata]);
            $created += $task ? 1 : 0;
        }

        return $created;
    }

    public function completeTasks(string $sourceEntity): void
    {
        $this->tasks->completeBySource('leave', $sourceEntity);
    }

    /** At most one in-app notification per (user, dedupe key). */
    public function notify(?int $userId, string $title, string $body, string $dedupeKey, ?string $action = null): bool
    {
        if (! $userId) {
            return false;
        }
        $inserted = DB::table('attendance_request_notices')->insertOrIgnore(['tenant_id' => app(TenantContext::class)->id, 'user_id' => $userId, 'dedupe_key' => mb_substr('leave:'.$dedupeKey, 0, 160), 'sent_at' => now()]);
        if (! $inserted) {
            return false;
        }
        $this->notifications->send($userId, $title, $body, 'leave', $action);

        return true;
    }

    // ---- files -----------------------------------------------------------------------------------

    /** @return array{path:string,name:string,mime:string,size:int} stored on the private leave disk after a real content-type check */
    public function storeFile(UploadedFile $file, string $uuid): array
    {
        abort_if($file->getSize() > self::MAX_FILE_BYTES, 422, 'The attachment is larger than 5 MB.');
        $facts = app(\App\Domain\People\Records\FileInspector::class)->inspectDocument($file);
        $path = app(TenantContext::class)->id.'/requests/'.$uuid.'.'.$facts['extension'];
        Storage::disk(RequestsBoot::DISK)->put($path, file_get_contents($file->getRealPath()));

        return ['path' => $path, 'name' => $facts['name'], 'mime' => $facts['mime'], 'size' => (int) $file->getSize()];
    }

    // ---- attendance coverage ---------------------------------------------------------------------

    /**
     * Tells Attendance that the leave coverage of these dates changed: records the domain event (payload: uuids and dates only)
     * and recalculates each day through AttendanceRecalculator once the surrounding transaction commits. Locked days are
     * skipped by the recalculator itself.
     *
     * @param  array<int,string>  $dates
     */
    public function coverageChanged(Employee $employee, array $dates, string $requestUuid, ?int $actorId, string $reason): void
    {
        $dates = array_values(array_unique($dates));
        sort($dates);
        if (! $dates) {
            return;
        }
        $this->events->record(LeaveEvents::COVERAGE_CHANGED, ['employee' => $employee, 'summary' => 'Leave coverage changed for '.count($dates).' day(s)', 'actor_id' => $actorId,
            'payload' => ['request' => $requestUuid, 'employee_id' => $employee->id, 'dates' => $dates, 'reason' => $reason], 'effective_date' => $dates[0]]);
        if (! app()->bound(AttendanceRecalculator::class)) {
            return;
        }
        $tenantId = app(TenantContext::class)->id;
        $employeeId = $employee->id;
        DB::afterCommit(function () use ($tenantId, $employeeId, $dates, $actorId) {
            $context = app(TenantContext::class);
            $previous = $context->id;
            $context->id = $tenantId;
            try {
                foreach ($dates as $date) {
                    app(AttendanceRecalculator::class)->recalculateDay($employeeId, $date, 'recalculation', $actorId);
                }
            } finally {
                $context->id = $previous;
            }
        });
    }
}
