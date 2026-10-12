<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\Classification;
use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Lifecycle\Models\{ExitInterview, LifecycleCase, LifecycleTask, TemplateTask};
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\{Employee, ReportingRelationship};
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Generates lifecycle instances from published templates, owns task completion, rescheduling, cancellation and readiness. Tasks surface in My Work. */
final class CaseService
{
    public function __construct(private TemplateService $templates, private DomainEvents $events) {}

    /** Idempotent: one open case per employee and kind (a second call returns the existing one). */
    public function generate(string $kind, Employee $employee, string $anchor, array $opts = []): LifecycleCase
    {
        return DB::transaction(function () use ($kind, $employee, $anchor, $opts) {
            Employee::whereKey($employee->id)->lockForUpdate()->first();
            $existing = LifecycleCase::where('employee_id', $employee->id)->where('kind', $kind)->when(in_array($kind, ['onboarding', 'offboarding'], true), fn ($q) => $q->where('status', 'open'),
                fn ($q) => $q->where('anchor_date', $anchor))->when($kind === 'absence_return', fn ($q) => $q->where('source', $opts['source'] ?? 'manual'))->first();
            if ($existing) return $existing;

            $version = $this->templates->resolve($kind, $employee, $opts, $anchor);
            $case = LifecycleCase::create(['kind' => $kind, 'employee_id' => $employee->id, 'version_id' => $version?->id, 'status' => 'open', 'anchor_date' => $anchor,
                'separation_record_id' => $opts['separation_record_id'] ?? null, 'move_id' => $opts['move_id'] ?? null, 'source' => $opts['source'] ?? 'manual']);
            $generated = [];
            foreach ($version ? TemplateTask::where('version_id', $version->id)->orderBy('sort')->get() : [] as $tt) {
                $task = LifecycleTask::create(['case_id' => $case->id, 'template_task_id' => $tt->id, 'group_name' => $tt->group_name, 'title' => $tt->title, 'description' => $tt->description,
                    'depends_on' => array_map(fn ($position) => $generated[$position], $tt->depends_on ?? []), 'acknowledgment_text' => $tt->acknowledgment_text,
                    'remind_before_days' => $tt->remind_before_days, 'escalate_after_days' => $tt->escalate_after_days,
                    'owner_type' => $tt->owner_type, 'owner_user_id' => $this->owner($tt->owner_type, $tt->owner_user_id, $employee, $anchor), 'due_date' => CarbonImmutable::parse($anchor)->addDays($tt->due_offset_days)->toDateString(),
                    'mandatory' => $tt->mandatory, 'clearance_dept' => $tt->clearance_dept, 'requires_document' => $tt->requires_document, 'status' => 'pending']);
                $this->workItem($case, $task);
                $generated[count($generated) + 1] = $task->id;
            }
            if ($kind === 'offboarding') {
                $years = (int) TenantClock::setting('lifecycle.exit_retention_years', 3);
                ExitInterview::create(['case_id' => $case->id, 'employee_id' => $employee->id, 'status' => 'pending', 'retention_until' => CarbonImmutable::parse($anchor)->addYears($years)->toDateString()]);
            }
            Audit::record('case.created', 'lifecycle', $case->uuid, ['kind' => $kind, 'employee' => $employee->uuid, 'source' => $case->source, 'template_version' => $version?->version]);
            $this->events->record('LifecycleCaseOpened', ['employee' => $employee, 'subject' => ['lifecycle_case', $case->id], 'effective_date' => $anchor, 'classification' => Classification::HR_CONFIDENTIAL,
                'summary' => ucfirst($kind).' checklist started.', 'payload' => ['case' => $case->uuid, 'kind' => $kind]]);

            return $case;
        });
    }

    public function addTask(User $actor, LifecycleCase $case, array $d): LifecycleTask
    {
        abort_unless($case->status === 'open', 409, 'This case is closed.');
        $employee = Employee::findOrFail($case->employee_id);
        $task = LifecycleTask::create(['case_id' => $case->id, 'group_name' => $d['group_name'] ?? 'General', 'title' => $d['title'], 'description' => $d['description'] ?? null, 'owner_type' => $d['owner_type'],
            'owner_user_id' => $this->owner($d['owner_type'], $d['owner_user_id'] ?? null, $employee, $case->anchor_date->toDateString()), 'due_date' => $d['due_date'] ?? null,
            'mandatory' => (bool) ($d['mandatory'] ?? false), 'clearance_dept' => $d['clearance_dept'] ?? null, 'requires_document' => (bool) ($d['requires_document'] ?? false), 'status' => 'pending']);
        $this->workItem($case, $task);
        Audit::record('task.added', 'lifecycle', $task->uuid, ['case' => $case->uuid]);

        return $task;
    }

    public function complete(User $actor, LifecycleTask $task, array $d): LifecycleTask
    {
        return DB::transaction(function () use ($actor, $task, $d) {
            $case = LifecycleCase::whereKey($task->case_id)->lockForUpdate()->firstOrFail();
            $task = LifecycleTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            $this->authorizeTask($actor, $task, $case, true);
            if ($task->status !== 'pending') return $task; // idempotent retry
            abort_unless($case->status === 'open', 409, 'This case is closed.');
            $dependencies = $task->depends_on ?? [];
            $resolved = LifecycleTask::where('case_id', $case->id)->whereIn('id', $dependencies)->whereIn('status', ['done', 'skipped'])->count();
            abort_unless($resolved === count($dependencies), 409, 'Complete the prerequisite tasks first.');
            if ($task->acknowledgment_text) {
                abort_unless($task->owner_user_id === $actor->id, 403, 'Only the assigned owner may acknowledge this task.');
                abort_unless(!empty($d['acknowledged']) && ($d['outcome'] ?? 'done') === 'done', 422, 'Read and acknowledge the statement before completing this task.');
            }
            if ($task->requires_document && empty($d['document_ref'])) {
                abort(response()->json(['message' => 'Attach or reference the required document before completing this task.', 'code' => 'document_required'], 422));
            }
            $skip = ($d['outcome'] ?? 'done') === 'skipped';
            abort_if($skip && $task->mandatory, 422, 'A mandatory task cannot be skipped.');
            $task->update(['status' => $skip ? 'skipped' : 'done', 'note' => $d['note'] ?? null, 'document_ref' => $d['document_ref'] ?? null, 'completed_by' => $actor->id, 'completed_at' => now(), 'acknowledged_at' => $task->acknowledgment_text ? now() : null]);
            app(TaskService::class)->completeBySource('lifecycle', 'task:'.$task->uuid);
            Audit::record('task.completed', 'lifecycle', $task->uuid, ['case' => $case->uuid, 'outcome' => $task->status]);
            $this->closeIfDone($case);

            return $task;
        });
    }

    /** Task owner, or lifecycle.manage in scope (clearance items: lifecycle.clearance.manage). */
    public function authorizeTask(User $actor, LifecycleTask $task, LifecycleCase $case, bool $write = false): void
    {
        if ($task->owner_user_id === $actor->id) return;
        $perm = $task->clearance_dept ? 'lifecycle.clearance.manage' : 'lifecycle.manage';
        abort_unless($actor->allows($perm) && app(DataScope::class)->canAccess($actor, $case->employee_id, $perm), 404);
    }

    public function reschedule(User $actor, LifecycleCase $case, string $newAnchor): LifecycleCase
    {
        return DB::transaction(function () use ($actor, $case, $newAnchor) {
            $case = LifecycleCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            abort_unless($case->status === 'open', 409, 'This case is closed.');
            $old = $case->anchor_date->toDateString();
            if ($old === $newAnchor) return $case;
            $delta = CarbonImmutable::parse($old)->diffInDays(CarbonImmutable::parse($newAnchor), false);
            foreach (LifecycleTask::where('case_id', $case->id)->where('status', 'pending')->whereNotNull('due_date')->get() as $t) {
                $t->update(['due_date' => $t->due_date->addDays((int) $delta)->toDateString(), 'reminded_at' => null, 'escalated_at' => null]);
                DB::table('work_items')->where('tenant_id', $case->tenant_id)->where('source_module', 'lifecycle')->where('source_entity', 'task:'.$t->uuid)->update(['due_date' => $t->due_date->toDateString()]);
            }
            $case->update(['anchor_date' => $newAnchor]);
            Audit::record('case.rescheduled', 'lifecycle', $case->uuid, ['from' => $old, 'to' => $newAnchor]);

            return $case;
        });
    }

    public function cancel(LifecycleCase $case, string $why): LifecycleCase
    {
        return DB::transaction(function () use ($case, $why) {
            $case = LifecycleCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($case->status !== 'open') return $case;
            foreach (LifecycleTask::where('case_id', $case->id)->where('status', 'pending')->get() as $t) {
                app(TaskService::class)->completeBySource('lifecycle', 'task:'.$t->uuid);
            }
            $case->update(['status' => 'cancelled']);
            Audit::record('case.cancelled', 'lifecycle', $case->uuid, ['reason' => $why]);

            return $case;
        });
    }

    public function closeIfDone(LifecycleCase $case): void
    {
        if ($case->status !== 'open') return;
        $pending = LifecycleTask::where('case_id', $case->id)->where('status', 'pending')->where('mandatory', true)->count();
        $total = LifecycleTask::where('case_id', $case->id)->count();
        if ($pending > 0 || $total === 0) return;
        $case->update(['status' => 'completed', 'completed_at' => now()]);
        $employee = Employee::find($case->employee_id);
        $this->events->record($case->kind === 'offboarding' ? 'OffboardingCleared' : 'LifecycleCaseCompleted', ['employee' => $employee, 'subject' => ['lifecycle_case', $case->id], 'classification' => Classification::HR_CONFIDENTIAL,
            'summary' => ucfirst($case->kind).' checklist completed.', 'payload' => ['case' => $case->uuid, 'kind' => $case->kind]]);
    }

    // ---- readiness ------------------------------------------------------------------------------------------------------------------

    public function readiness(LifecycleCase $case): array
    {
        $today = TenantClock::today();
        $tasks = LifecycleTask::where('case_id', $case->id)->get();
        $done = $tasks->whereIn('status', ['done', 'skipped'])->count();
        $open = $tasks->where('status', 'pending');
        $anchor = $case->anchor_date->toDateString();
        $dayOne = $open->where('mandatory', true)->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() <= $anchor);
        $owners = User::whereIn('id', $open->pluck('owner_user_id')->filter())->pluck('name', 'id');

        return [
            'total' => $tasks->count(), 'done' => $done, 'percent' => $tasks->count() ? (int) round($done * 100 / $tasks->count()) : 100,
            'overdue' => $open->filter(fn ($t) => $t->due_date && $t->due_date->toDateString() < $today)->count(),
            'mandatory_pending' => $open->where('mandatory', true)->count(),
            'day_one_ready' => $case->kind === 'onboarding' ? $dayOne->isEmpty() : null,
            'blockers' => $dayOne->map(fn ($t) => ['task' => $t->uuid, 'title' => $t->title, 'owner' => $t->owner_user_id ? ($owners[$t->owner_user_id] ?? null) : null, 'due_date' => $t->due_date?->toDateString()])->values()->all(),
        ];
    }

    public function presentTask(LifecycleTask $t): array
    {
        $pendingDependencies = LifecycleTask::where('case_id', $t->case_id)->whereIn('id', $t->depends_on ?? [])->where('status', 'pending')->get(['uuid', 'title']);
        return ['uuid' => $t->uuid, 'group' => $t->group_name, 'title' => $t->title, 'description' => $t->description, 'owner_type' => $t->owner_type, 'owner_user_id' => $t->owner_user_id,
            'blocked_by' => $pendingDependencies->toArray(), 'acknowledgment_text' => $t->acknowledgment_text, 'acknowledged_at' => $t->acknowledged_at?->toIso8601String(),
            'due_date' => $t->due_date?->toDateString(), 'mandatory' => $t->mandatory, 'clearance_dept' => $t->clearance_dept, 'requires_document' => $t->requires_document, 'document_ref' => $t->document_ref,
            'status' => $t->status, 'overdue' => $t->status === 'pending' && $t->due_date && $t->due_date->toDateString() < TenantClock::today(), 'escalated' => (bool) $t->escalated_at,
            'completed_at' => $t->completed_at?->toIso8601String()];
    }

    public function presentCase(LifecycleCase $c, bool $detail = false): array
    {
        $e = Employee::with('person')->find($c->employee_id);
        $row = ['uuid' => $c->uuid, 'kind' => $c->kind, 'status' => $c->status, 'anchor_date' => $c->anchor_date->toDateString(), 'source' => $c->source,
            'employee' => ['uuid' => $e->uuid, 'name' => $e->person?->display_name, 'employee_number' => $e->employee_number, 'status' => $e->status], 'readiness' => $this->readiness($c)];
        if ($detail) {
            $row['tasks'] = LifecycleTask::where('case_id', $c->id)->orderBy('id')->get()->map(fn ($t) => $this->presentTask($t))->all();
            $row['settlement_uuid'] = $c->settlement_uuid;
        }

        return $row;
    }

    // ---- owners + My Work -----------------------------------------------------------------------------------------------------------

    public function owner(string $type, ?int $named, Employee $employee, string $date): ?int
    {
        $active = fn (?int $id) => $id && User::where('tenant_id', $employee->tenant_id)->where('active', true)->whereKey($id)->exists() ? $id : null;
        $id = match ($type) {
            'employee' => $active(DB::table('people')->where('id', $employee->person_id)->value('user_id')),
            'manager' => $this->managerUser($employee, $date),
            default => $active($named),
        };

        return $id ?? ($type === 'employee' ? null : $this->hrFallback($employee->tenant_id));
    }

    public static function usersWith(int $tenantId, string $permission): array
    {
        return DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('users.tenant_id', $tenantId)->where('users.active', true)->where('permissions.key', $permission)->distinct()->orderBy('users.id')->pluck('users.id')->all();
    }

    public function notify(int $userId, string $title, string $body, string $action = '/lifecycle'): void
    {
        try {
            app(NotificationService::class)->send($userId, $title, $body, 'lifecycle', $action);
        } catch (\Throwable) {
        }
    }

    private function hrFallback(int $tenantId): ?int
    {
        return self::usersWith($tenantId, 'lifecycle.manage')[0] ?? null;
    }

    private function managerUser(Employee $employee, string $date): ?int
    {
        $rel = ReportingRelationship::where('employee_id', $employee->id)->where('type', 'primary')->where('effective_from', '<=', max($date, $employee->joined_on->toDateString()))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first()
            ?? ReportingRelationship::where('employee_id', $employee->id)->where('type', 'primary')->orderByDesc('effective_from')->first();
        if (! $rel?->manager_id) return null;
        $uid = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $rel->manager_id)->value('people.user_id');

        return $uid && User::where('active', true)->whereKey($uid)->exists() ? (int) $uid : null;
    }

    private function workItem(LifecycleCase $case, LifecycleTask $task): void
    {
        if (! $task->owner_user_id) return;
        try {
            app(TaskService::class)->createOnce(['assignee_id' => $task->owner_user_id, 'title' => $task->title, 'description' => ucfirst($case->kind).' checklist: '.$task->group_name, 'source_module' => 'lifecycle',
                'source_entity' => 'task:'.$task->uuid, 'priority' => $task->mandatory ? 'high' : 'normal', 'due_date' => $task->due_date?->toDateString(), 'action' => '/lifecycle/tasks/'.$task->uuid]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
