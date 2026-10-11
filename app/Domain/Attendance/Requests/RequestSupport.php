<?php

namespace App\Domain\Attendance\Requests;

use App\Domain\Access\DataScope;
use App\Domain\Attendance\Contracts\AttendanceRecalculator;
use App\Domain\Attendance\Contracts\LockGuard;
use App\Domain\Attendance\Contracts\ScheduleResolver;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Shared plumbing for the request services: scope, locks, recalculation, My Work tasks, deduplicated notifications, attachments. */
final class RequestSupport
{
    public const DISK = 'attendance_requests_private';

    public const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    public function __construct(private TaskService $tasks, private NotificationService $notifications, private DataScope $scope) {}

    // ---- scope ---------------------------------------------------------------------------------

    /** Resolves an employee UUID the actor may act on; out-of-scope is reported as 404 (existence never disclosed). */
    public function employee(User $actor, string $uuid, string $permission = 'attendance.manage', bool $allowSelf = true): Employee
    {
        $employee = Employee::query()->where('uuid', $uuid)->first();
        abort_unless($employee && (($allowSelf && $this->scope->isSelf($actor, $employee)) || $this->scope->canAccess($actor, $employee, $permission)), 404, 'That employee was not found.');

        return $employee;
    }

    /** The actor's own working employment (the first one that is active), or 422. */
    public function ownEmployee(User $actor): Employee
    {
        $ids = $this->scope->ownEmployeeIds($actor);
        $employee = $ids ? Employee::query()->whereIn('id', $ids)->orderBy('id')->get()->first(fn ($e) => $e->isWorking()) : null;
        abort_unless($employee, 422, 'Your login is not linked to an active employment, so there is nothing to submit for.');

        return $employee;
    }

    public function isSelf(User $actor, Employee|int $employee): bool
    {
        return $this->scope->isSelf($actor, $employee);
    }

    public function scope(): DataScope
    {
        return $this->scope;
    }

    // ---- locks and recalculation ---------------------------------------------------------------

    public function assertUnlocked(int $employeeId, string $date): void
    {
        if (app()->bound(LockGuard::class)) {
            app(LockGuard::class)->assertUnlocked($employeeId, $date);
        }
    }

    /** Asks the processor to recalculate after the surrounding transaction commits. A missing processor is simply a no-op. */
    public function recalculate(int $employeeId, string $date, string $trigger, ?int $by = null): void
    {
        if (! app()->bound(AttendanceRecalculator::class)) {
            return;
        }
        $tenantId = app(TenantContext::class)->id;
        DB::afterCommit(function () use ($employeeId, $date, $trigger, $by, $tenantId) {
            $context = app(TenantContext::class);
            $previous = $context->id;
            $context->id = $tenantId;
            try {
                app(AttendanceRecalculator::class)->recalculateDay($employeeId, $date, $trigger, $by);
            } finally {
                $context->id = $previous;
            }
        });
    }

    // ---- My Work and notifications -------------------------------------------------------------

    /**
     * One open My Work task per assignee and source entity (idempotent). Returns the number of tasks newly created.
     * TaskService already announces a new task to its assignee, which is the approver's "request submitted" notification.
     */
    public function assignTasks(iterable $assignees, string $title, string $description, string $sourceEntity, string $action, ?string $dueDate = null, array $metadata = []): int
    {
        $created = 0;
        foreach ($assignees as $user) {
            $task = $this->tasks->createOnce(['assignee_id' => $user instanceof User ? $user->id : (int) $user, 'title' => $title, 'description' => $description, 'source_module' => 'attendance',
                'source_entity' => $sourceEntity, 'priority' => 'normal', 'due_date' => $dueDate, 'action' => $action, 'metadata' => $metadata]);
            $created += $task ? 1 : 0;
        }

        return $created;
    }

    public function completeTasks(string $sourceEntity): void
    {
        $this->tasks->completeBySource('attendance', $sourceEntity);
    }

    /**
     * Sends an in-app notification at most once per (user, dedupe key). Reminders pass $respectQuiet so nothing is sent during
     * the tenant's configured quiet hours; a suppressed notice is NOT recorded, so the next run delivers it.
     */
    public function notify(?int $userId, string $title, string $body, string $dedupeKey, ?string $action = null, bool $respectQuiet = false, ?string $timezone = null): bool
    {
        if (! $userId || ($respectQuiet && $this->inQuietHours($timezone))) {
            return false;
        }
        $inserted = DB::table('attendance_request_notices')->insertOrIgnore(['tenant_id' => app(TenantContext::class)->id, 'user_id' => $userId, 'dedupe_key' => mb_substr($dedupeKey, 0, 160), 'sent_at' => now()]);
        if (! $inserted) {
            return false;
        }
        $this->notifications->send($userId, $title, $body, 'attendance', $action);

        return true;
    }

    /** quiet_hours setting: {start:"21:00", end:"07:00"} in the given (default tenant) timezone; windows may wrap midnight. */
    public function inQuietHours(?string $timezone = null): bool
    {
        $quiet = RequestRules::setting('notifications.quiet_hours');
        if (! is_array($quiet) || empty($quiet['start']) || empty($quiet['end'])) {
            return false;
        }
        $now = CarbonImmutable::now($timezone ?: TenantClock::timezone())->format('H:i');
        [$start, $end] = [$quiet['start'], $quiet['end']];

        return $start <= $end ? ($now >= $start && $now < $end) : ($now >= $start || $now < $end);
    }

    // ---- attachments ---------------------------------------------------------------------------

    /** @return array{path:string,name:string}  stored on the private attendance disk after a real content-type check */
    public function storeAttachment(UploadedFile $file, string $folder, string $uuid): array
    {
        abort_if($file->getSize() > self::MAX_ATTACHMENT_BYTES, 422, 'The attachment is larger than 5 MB.');
        $facts = app(\App\Domain\People\Records\FileInspector::class)->inspectDocument($file);
        $path = app(TenantContext::class)->id.'/'.$folder.'/'.$uuid.'.'.$facts['extension'];
        Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

        return ['path' => $path, 'name' => $facts['name'], 'mime' => $facts['mime']];
    }

    /** The shift's zone when the schedule resolver knows it, else the employee's own zone. */
    public function zoneFor(Employee $employee, string $date): string
    {
        if (app()->bound(ScheduleResolver::class)) {
            try {
                $zone = app(ScheduleResolver::class)->resolve($employee->id, $date)->timezone;
                if ($zone) {
                    return $zone;
                }
            } catch (\Throwable) {
                // the employee's zone below is always a valid fallback
            }
        }

        return $employee->timezone ?: 'UTC';
    }

    public static function iso(?string $utc): ?string
    {
        return $utc ? CarbonImmutable::parse($utc, 'UTC')->toIso8601String() : null;
    }

    /** Employee-local date today (tenant clock for the employee's zone). */
    public static function today(Employee $employee): string
    {
        return TenantClock::today($employee->timezone ?: null);
    }
}
