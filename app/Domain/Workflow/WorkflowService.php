<?php

namespace App\Domain\Workflow;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvent;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Domain\Workflow\Models\Assignment;
use App\Domain\Workflow\Models\Definition;
use App\Domain\Workflow\Models\Execution;
use App\Domain\Workflow\Models\Instance;
use App\Domain\Workflow\Models\Version;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Bounded, declarative orchestration. No eval, arbitrary SQL, arbitrary URLs or financial effects. */
final class WorkflowService
{
    public function manager(User $u, string $permission = 'workflows.manage'): void
    {
        abort_unless($u->allows($permission) && app(DataScope::class)->hasTenantWide($u, $permission), 403);
    }

    public function validateSpec(array $input): array
    {
        $spec = validator($input, [
            'trigger' => 'sometimes|array:type,event_type,status,employee_uuid,at,interval_hours',
            'trigger.type' => 'required_with:trigger|in:manual,event,schedule',
            'trigger.event_type' => 'required_if:trigger.type,event|in:'.implode(',', array_keys(WorkflowEventCatalog::EVENTS)),
            'trigger.status' => 'required_if:trigger.event_type,EmploymentStatusChanged|in:'.implode(',', WorkflowEventCatalog::EMPLOYMENT_STATUSES),
            'trigger.employee_uuid' => 'required_if:trigger.type,schedule|uuid',
            'trigger.at' => 'required_if:trigger.type,schedule|date_format:Y-m-d\TH:i:sP',
            'trigger.interval_hours' => 'sometimes|integer|min:0|max:8760',
            'steps' => 'required|array|min:1|max:25', 'steps.*' => 'array:title,action,assignees,assignment,mode,timeout_hours,worker_type,escalation_assignees,escalation_hours',
            'steps.*.escalation_assignees' => 'sometimes|array|max:10',
            'steps.*.escalation_assignees.*' => 'required|integer',
            'steps.*.escalation_hours' => 'sometimes|integer|min:1|max:8760',
            'steps.*.title' => 'required|string|max:150', 'steps.*.action' => 'required|in:approval,task,notify,wait',
            'steps.*.assignment' => 'required|in:fixed,manager', 'steps.*.assignees' => 'present|array|max:10',
            'steps.*.assignees.*' => 'required|integer', 'steps.*.mode' => 'required|in:all,any',
            'steps.*.timeout_hours' => 'required|integer|min:1|max:8760',
            'steps.*.worker_type' => 'nullable|in:employee,contractor,intern,consultant',
        ])->validate();
        if (($spec['trigger']['type'] ?? '') === 'schedule') {
            Employee::where('uuid', $spec['trigger']['employee_uuid'])->firstOrFail();
        }
        if (isset($spec['trigger']['status']) && ($spec['trigger']['event_type'] ?? null) !== 'EmploymentStatusChanged') {
            $this->invalid('A target status is only valid for an employment status event.');
        }
        foreach ($spec['steps'] as $step) {
            if (count(array_unique($step['assignees'])) !== count($step['assignees'])) {
                $this->invalid('An assignee may appear only once in each step.');
            }
            if ($step['action'] !== 'wait' && $step['assignment'] === 'fixed' && ! count($step['assignees'])) {
                $this->invalid('Choose at least one assignee.');
            }
            if (count(array_unique($step['escalation_assignees'] ?? [])) !== count($step['escalation_assignees'] ?? [])) {
                $this->invalid('An escalation assignee may appear only once per step.');
            }
            if (!empty($step['escalation_assignees']) && !in_array($step['action'], ['task', 'approval'])) {
                $this->invalid('Only task and approval steps support escalation.');
            }
            foreach ([...$step['assignees'], ...($step['escalation_assignees'] ?? [])] as $id) {
                $u = User::where('tenant_id', app(TenantContext::class)->id)->whereKey($id)->first();
                if (! $u || ! $u->allows('workflows.act')) {
                    $this->invalid('Every assignee must be an active workflow participant in this workspace.');
                }
            }
        }

        return $spec;
    }

    public function draft(User $u, array $data, ?string $uuid = null): Definition
    {
        $this->manager($u);
        $spec = $this->validateSpec($data['spec']);

        return DB::transaction(function () use ($u, $data, $spec, $uuid) {
            if ($uuid) {
                $d = Definition::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
                abort_if($d->revision !== (int) $data['revision'], 409, 'Reload the current draft.');
                $d->revision++;
                $d->draft = $spec;
                $d->name = $data['name'];
                $d->created_by = $u->id;
                $d->save();
            } else {
                $d = Definition::create(['name' => $data['name'], 'draft' => $spec, 'revision' => 1, 'created_by' => $u->id]);
            }
            Audit::record('workflow.drafted', 'workflows', 'definition:'.$d->uuid);

            return $d;
        });
    }

    public function publish(User $u, string $uuid, int $revision): Definition
    {
        $this->manager($u, 'workflows.publish');

        return DB::transaction(function () use ($u, $uuid, $revision) {
            $d = Definition::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_if($d->revision !== $revision, 409, 'Reload the current draft.');
            abort_if($d->created_by === $u->id, 403, 'Another authorized administrator must publish this draft.');
            $spec = $this->validateSpec($d->draft);
            $spec['event_after_id'] = (int) DomainEvent::max('id');
            $v = Version::firstOrCreate(['definition_id' => $d->id, 'number' => $d->revision], ['spec' => $spec, 'published_by' => $u->id]);
            $d->published_version_id = $v->id;
            $d->save();
            Audit::record('workflow.published', 'workflows', 'definition:'.$d->uuid);

            return $d;
        });
    }

    public function visible(User $u)
    {
        $q = Instance::query();
        if ($u->allows('workflows.manage') && app(DataScope::class)->hasTenantWide($u, 'workflows.manage')) {
            return $q;
        }
        $q->where(fn ($q) => $q->where('initiated_by', $u->id)->orWhereIn('id', Assignment::where('user_id', $u->id)->select('instance_id')));

        return app(DataScope::class)->constrain($q, $u, 'people.view', 'workflow_instances.employee_id');
    }

    public function start(User $u, string $definition, string $employeeUuid, string $key, ?int $pinnedVersion = null): Instance
    {
        $this->manager($u, 'workflows.run');
        abort_unless(in_array('people', $u->tenant->settings['modules'] ?? [], true), 403, 'The People module must be enabled.');
        $employee = Employee::where('uuid', $employeeUuid)->firstOrFail();
        abort_unless($u->allows('people.view') && app(DataScope::class)->canAccess($u, $employee, 'people.view'), 403);

        return DB::transaction(function () use ($u, $definition, $employee, $key, $pinnedVersion) {
            // Serialize tenant starts, including key collisions across definitions.
            DB::table('tenants')->where('id', $u->tenant_id)->lockForUpdate()->first();
            $d = Definition::where('uuid', $definition)->firstOrFail();
            if ($existing = Instance::where('idempotency_key', $key)->first()) {
                abort_unless($existing->definition_id === $d->id && $existing->employee_id === $employee->id && $existing->initiated_by === $u->id, 409, 'Idempotency key already used for another request.');

                return $existing;
            }
            if (! $d->published_version_id) {
                $this->invalid('Publish a version before starting a workflow.');
            }
            if ($pinnedVersion && (int) $d->published_version_id !== $pinnedVersion) {
                $this->invalid('This automatic delivery was superseded by a newer published version.');
            }
            $i = Instance::create(['definition_id' => $d->id, 'version_id' => $d->published_version_id, 'employee_id' => $employee->id, 'initiated_by' => $u->id,
                'idempotency_key' => $key, 'status' => 'running', 'step_index' => 0, 'attempts' => 0, 'context' => ['worker_type' => $employee->worker_type]]);
            $this->log($i, 'started', $u->id);
            $this->advance($i);

            return $i->refresh();
        });
    }

    private function recipients(Instance $i, array $s): array
    {
        $ids = $s['assignees'];
        if ($s['assignment'] === 'manager') {
            $manager = DB::table('employee_reporting_relationships')->where('tenant_id', $i->tenant_id)->where('employee_id', $i->employee_id)->where('type', 'primary')
                ->where('effective_from', '<=', TenantClock::today())->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', TenantClock::today()))->orderByDesc('effective_from')->value('manager_id');
            $userId = $manager ? DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.tenant_id', $i->tenant_id)->where('employees.id', $manager)->value('people.user_id') : null;
            if ($userId) {
                $ids = [$userId];
            } // configured fixed assignees are the missing-manager fallback.
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function eligible(Instance $i, int $id, bool $approval): bool
    {
        $u = User::where('tenant_id', $i->tenant_id)->whereKey($id)->first();

        return $u && $u->allows('workflows.act') && $u->allows('people.view') && app(DataScope::class)->canAccess($u, $i->employee_id, 'people.view')
            && (! $approval || ($u->id !== $i->initiated_by && ! app(DataScope::class)->isSelf($u, $i->employee_id)));
    }

    private function authority(Instance $i): bool
    {
        $u = User::where('tenant_id', $i->tenant_id)->find($i->initiated_by);

        return $u && in_array('people', $u->tenant->settings['modules'] ?? [], true) && $u->allows('workflows.run') && $u->allows('people.view') && app(DataScope::class)->hasTenantWide($u, 'workflows.run')
            && app(DataScope::class)->canAccess($u, $i->employee_id, 'people.view');
    }

    private function advance(Instance $i): void
    {
        if (! $this->authority($i)) {
            $this->fail($i, 'initiator_permission_changed');

            return;
        }
        $steps = Version::findOrFail($i->version_id)->spec['steps'];
        while ($i->step_index < count($steps)) {
            $s = $steps[$i->step_index];
            if (! empty($s['worker_type']) && $s['worker_type'] !== ($i->context['worker_type'] ?? null)) {
                $this->log($i, 'condition_skipped');
                $i->step_index++;
                $i->save();

                continue;
            }
            if ($s['action'] === 'wait') {
                $i->status = 'waiting';
                $i->due_at = now()->addHours($s['timeout_hours']);
                $i->save();
                $this->log($i, 'timer_started');

                return;
            }
            $ids = $this->recipients($i, $s);
            $existing = Assignment::where('instance_id', $i->id)->where('step_index', $i->step_index)->get();
            if ($existing->isNotEmpty()) {
                // Retry the unresolved assignments, including delegated/escalated recipients.
                $ids = $existing->where('status', 'pending')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
            }
            if (! $ids || collect($ids)->contains(fn ($id) => ! $this->eligible($i, $id, $s['action'] === 'approval'))) {
                $this->fail($i, 'assignee_unavailable_or_out_of_scope');

                return;
            }
            if ($s['action'] === 'notify') {
                foreach ($ids as $id) {
                    app(NotificationService::class)->send($id, 'Workflow notification', $s['title'], 'workflows', '/workflows/instances/'.$i->uuid);
                }
                $this->log($i, 'notification_sent');
                $i->step_index++;
                $i->save();

                continue;
            }
            $i->due_at = now()->addHours($s['timeout_hours']);
            $i->status = 'waiting';
            $i->save();
            foreach ($ids as $id) {
                $a = Assignment::firstOrCreate(['instance_id' => $i->id, 'step_index' => $i->step_index, 'user_id' => $id], ['status' => 'pending']);
                if ($a->status !== 'pending') {
                    continue;
                }
                $this->task($i, $s, $id);
            }
            $this->log($i, 'assignments_created');

            return;
        }
        $i->status = 'completed';
        $i->due_at = null;
        $i->save();
        $this->log($i, 'completed');
    }

    private function source(Instance $i): string
    {
        return $i->uuid.':'.$i->step_index;
    }

    private function task(Instance $i, array $step, int $userId): void
    {
        app(TaskService::class)->createOnce(['title' => $step['title'], 'priority' => 'normal', 'assignee_id' => $userId,
            'due_date' => $i->due_at->toDateString(), 'source_module' => 'workflows', 'source_entity' => $this->source($i),
            'action' => '/workflows/instances/'.$i->uuid, 'status' => 'open', 'metadata' => ['workflow_instance' => $i->uuid]]);
    }

    private function reassign(Instance $i, array $step, Assignment $from, int $target, string $reason, ?int $actor, string $event): void
    {
        $from->update(['status' => $event, 'reason' => $reason, 'decided_at' => now()]);
        WorkItem::where('source_module', 'workflows')->where('source_entity', $this->source($i))->where('assignee_id', $from->user_id)
            ->where('status', 'open')->update(['status' => 'completed', 'completed_at' => now()]);
        Assignment::create(['instance_id' => $i->id, 'step_index' => $i->step_index, 'user_id' => $target, 'status' => 'pending']);
        $this->task($i, $step, $target);
        $this->log($i, $event, $actor, ['from_user' => $from->user_id, 'to_user' => $target, 'reason' => $reason]);
    }

    public function delegate(User $u, string $uuid, int $fromUser, int $target, string $reason): Instance
    {
        return DB::transaction(function () use ($u, $uuid, $fromUser, $target, $reason) {
            $i = $this->visible($u)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($i->status !== 'waiting' || $i->due_at?->isPast()) $this->invalid('Only pending work before its deadline can be delegated.');
            $step = Version::findOrFail($i->version_id)->spec['steps'][$i->step_index];
            abort_unless(in_array($step['action'], ['task', 'approval']), 422);
            $manager = $u->allows('workflows.manage') && app(DataScope::class)->hasTenantWide($u, 'workflows.manage');
            abort_unless($manager || ($u->id === $fromUser && $this->eligible($i, $u->id, $step['action'] === 'approval')), 403);
            if (!$this->authority($i)) $this->invalid('The initiator no longer has execution authority.');
            if (!$this->eligible($i, $target, $step['action'] === 'approval')) $this->invalid('The new assignee lacks independent approval authority or employee access.');
            $assignments = Assignment::where('instance_id', $i->id)->where('step_index', $i->step_index);
            if ((clone $assignments)->where('user_id', $target)->exists()) $this->invalid('Choose someone who has not already participated in this step.');
            $from = (clone $assignments)->where('user_id', $fromUser)->where('status', 'pending')->firstOrFail();
            $this->reassign($i, $step, $from, $target, $reason, $u->id, 'delegated');
            return $i;
        });
    }

    public function delegationOptions(User $u, string $uuid): array
    {
        $i = $this->visible($u)->where('uuid', $uuid)->firstOrFail();
        $manager = $u->allows('workflows.manage') && app(DataScope::class)->hasTenantWide($u, 'workflows.manage');
        $ownsPendingAssignment = Assignment::where('instance_id', $i->id)->where('step_index', $i->step_index)
            ->where('user_id', $u->id)->where('status', 'pending')->exists();
        abort_unless($manager || $ownsPendingAssignment, 403);
        $step = Version::findOrFail($i->version_id)->spec['steps'][$i->step_index] ?? null;
        if (!$step || !in_array($step['action'], ['approval','task']) || $i->status !== 'waiting' || $i->due_at?->isPast()) return [];
        $used = Assignment::where('instance_id', $i->id)->where('step_index', $i->step_index)->pluck('user_id');
        return User::where('tenant_id', $i->tenant_id)->where('active', true)->whereNotIn('id', $used)
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', 'workflows.act'))->get(['id','name','tenant_id','active'])
            ->filter(fn ($candidate) => $this->eligible($i, $candidate->id, $step['action'] === 'approval'))->take(100)
            ->map(fn ($candidate) => ['id' => $candidate->id, 'name' => $candidate->name])->values()->all();
    }

    private function expire(Instance $i, array $step): void
    {
        if (!$this->authority($i)) { $this->fail($i, 'initiator_permission_changed'); return; }
        $targets = $step['escalation_assignees'] ?? [];
        if (!$targets || Execution::where('instance_id', $i->id)->where('step_index', $i->step_index)->where('event', 'escalated')->exists()) {
            $this->fail($i, 'deadline_exceeded'); return;
        }
        $assignments = Assignment::where('instance_id', $i->id)->where('step_index', $i->step_index)->get();
        $pending = $assignments->where('status', 'pending')->values();
        $targets = array_values(array_filter($targets, fn ($id) => !$assignments->contains('user_id', $id) && $this->eligible($i, (int)$id, $step['action'] === 'approval')));
        if (!$pending->count() || count($targets) < $pending->count()) { $this->fail($i, 'escalation_assignee_unavailable'); return; }
        $i->due_at = now()->addHours($step['escalation_hours'] ?? 24); $i->save();
        foreach ($pending as $index => $from) $this->reassign($i, $step, $from, (int)$targets[$index], 'Deadline exceeded; published escalation policy applied.', null, 'escalated');
    }

    private function close(Instance $i): void
    {
        app(TaskService::class)->completeBySource('workflows', $this->source($i));
    }

    private function fail(Instance $i, string $code): void
    {
        $i->status = $i->attempts >= 3 ? 'dead_letter' : 'failed';
        $i->failure_code = $code;
        $i->due_at = null;
        $i->save();
        $this->close($i);
        $this->log($i, $i->status, null, ['code' => $code]);
    }

    public function decide(User $u, string $uuid, string $decision, string $reason): Instance
    {
        return DB::transaction(function () use ($u, $uuid, $decision, $reason) {
            $i = $this->visible($u)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($i->status !== 'waiting') {
                $this->invalid('This workflow is not awaiting a decision.');
            }
            $s = Version::findOrFail($i->version_id)->spec['steps'][$i->step_index];
            abort_unless(in_array($s['action'], ['approval', 'task']) && $this->eligible($i, $u->id, $s['action'] === 'approval'), 403);
            if (! $this->authority($i)) {
                $this->fail($i, 'initiator_permission_changed');

                return $i;
            }
            if ($i->due_at?->isPast()) {
                $this->expire($i, $s);

                return $i;
            }
            $a = Assignment::where('instance_id', $i->id)->where('step_index', $i->step_index)->where('user_id', $u->id)->firstOrFail();
            if ($a->status !== 'pending') {
                $this->invalid('This assignment was already decided.');
            }
            $a->update(['status' => $decision, 'reason' => $reason, 'decided_at' => now()]);
            WorkItem::where('source_module', 'workflows')->where('source_entity', $this->source($i))->where('assignee_id', $u->id)->where('status', 'open')->update(['status' => 'completed', 'completed_at' => now()]);
            $this->log($i, $decision, $u->id);
            if ($decision === 'rejected') {
                $this->close($i);
                $i->status = 'rejected';
                $i->due_at = null;
                $i->save();

                return $i;
            }
            $pending = Assignment::where('instance_id', $i->id)->where('step_index', $i->step_index)->where('status', 'pending');
            if ($s['mode'] === 'any' || ! $pending->exists()) {
                $pending->update(['status' => 'skipped']);
                $this->close($i);
                $i->step_index++;
                $i->status = 'running';
                $i->due_at = null;
                $i->save();
                $this->advance($i);
            }

            return $i->refresh();
        });
    }

    public function control(User $u, string $uuid, string $action, string $reason): Instance
    {
        $this->manager($u);

        return DB::transaction(function () use ($u, $uuid, $action, $reason) {
            $i = Instance::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($action === 'cancel' && in_array($i->status, ['running', 'waiting', 'paused', 'failed', 'dead_letter'])) {
                $this->close($i);
                $i->status = 'cancelled';
                $i->due_at = null;
                Assignment::where('instance_id', $i->id)->where('status', 'pending')->update(['status' => 'cancelled']);
            } elseif ($action === 'pause' && $i->status === 'waiting') {
                $i->status = 'paused';
            } elseif ($action === 'resume' && $i->status === 'paused') {
                $i->status = 'waiting';
            } elseif ($action === 'retry' && $i->status === 'failed') {
                $i->attempts++;
                $i->status = 'running';
                $i->failure_code = null;
                $i->save();
                $this->advance($i);
            } else {
                $this->invalid('This recovery action is not valid for the current state.');
            }
            $i->save();
            $this->log($i, $action, $u->id, ['reason' => $reason]);

            return $i;
        });
    }

    public function tick(): int
    {
        $count = 0;
        foreach (Instance::where('status', 'waiting')->where('due_at', '<=', now())->limit(200)->pluck('id') as $id) {
            DB::transaction(function () use ($id) {
                $i = Instance::whereKey($id)->lockForUpdate()->first();
                if (! $i || $i->status !== 'waiting' || $i->due_at->isFuture()) {
                    return;
                }
                $s = Version::findOrFail($i->version_id)->spec['steps'][$i->step_index];
                if ($s['action'] === 'wait') {
                    $i->step_index++;
                    $i->status = 'running';
                    $i->due_at = null;
                    $i->save();
                    $this->advance($i);
                } else {
                    $this->expire($i, $s);
                }
            });
            $count++;
        }

        return $count;
    }

    public function simulate(User $u, array $spec, string $employeeUuid): array
    {
        $this->manager($u);
        $spec = $this->validateSpec($spec);
        $e = Employee::where('uuid', $employeeUuid)->firstOrFail();
        abort_unless($u->allows('people.view') && app(DataScope::class)->canAccess($u, $e), 403);
        $i = new Instance(['employee_id' => $e->id, 'initiated_by' => $u->id]);
        $i->tenant_id = $u->tenant_id;

        return ['side_effects' => false, 'steps' => collect($spec['steps'])->map(function ($s, $index) use ($e, $i) {
            $ids = $s['action'] === 'wait' ? [] : $this->recipients($i, $s);

            return ['step' => $index + 1, 'title' => $s['title'], 'action' => $s['action'], 'mode' => $s['mode'],
                'matches' => empty($s['worker_type']) || $s['worker_type'] === $e->worker_type,
                'assignees' => $ids, 'blocked' => $s['action'] !== 'wait' && (! $ids || collect($ids)->contains(fn ($id) => ! $this->eligible($i, $id, $s['action'] === 'approval')))];
        })->all()];
    }

    public function present(Instance $i, User $u, bool $detail = false): array
    {
        $s = Version::findOrFail($i->version_id);
        $result = ['uuid' => $i->uuid, 'name' => Definition::findOrFail($i->definition_id)->name, 'version' => $s->number, 'status' => $i->status,
            'step' => $i->step_index + 1, 'step_count' => count($s->spec['steps']), 'due_at' => $i->due_at?->toIso8601String(), 'failure_code' => $i->failure_code, 'attempts' => $i->attempts];
        if ($detail) {
            $step = $s->spec['steps'][$i->step_index] ?? null;
            $result['current_step'] = $step;
            $result['can_decide'] = $step && $i->status === 'waiting' && $this->eligible($i, $u->id, $step['action'] === 'approval')
                && Assignment::where('instance_id', $i->id)->where('step_index', $i->step_index)->where('user_id', $u->id)->where('status', 'pending')->exists();
            $result['my_assignment_user_id'] = $result['can_decide'] ? $u->id : null;
            $result['assignments'] = Assignment::where('instance_id', $i->id)->get(['step_index', 'user_id', 'status', 'reason', 'decided_at']);
            $result['trace'] = Execution::where('instance_id', $i->id)->orderBy('id')->get(['step_index', 'event', 'actor_id', 'details', 'created_at']);
        }

        return $result;
    }

    private function log(Instance $i, string $event, ?int $actor = null, ?array $details = null): void
    {
        Execution::create(['instance_id' => $i->id, 'step_index' => $i->step_index, 'event' => $event, 'actor_id' => $actor, 'details' => $details]);
        Audit::record('workflow.'.$event, 'workflows', 'instance:'.$i->uuid);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }
}
