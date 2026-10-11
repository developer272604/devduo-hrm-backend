<?php

namespace App\Domain\Performance;

use App\Domain\Attendance\Requests\ApproverResolver;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\People\Models\Employee;
use App\Domain\Performance\Models\{Checkin, Cycle, Goal, KeyResult};
use App\Domain\Performance\Support\Num;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Goal framework: company/department/team/individual goals, OKR objectives + key results, KPIs, cascading, approval, check-ins and decimal-safe progress rollup. */
final class GoalService
{
    public const LEVELS = ['company' => 0, 'department' => 1, 'team' => 2, 'individual' => 3];

    public function __construct(private PerformanceAccess $access, private DomainEvents $events) {}

    public function create(User $actor, array $d, ?Employee $owner): Goal
    {
        $level = $d['level'];
        $status = 'active'; $approvedBy = $actor->id;
        if ($level === 'individual') {
            if (! $owner) throw ValidationException::withMessages(['owner_uuid' => 'An individual goal needs an owner.']);
            if ($this->access->isSelf($actor, $owner)) {
                abort_unless($actor->allows('performance.self'), 403, 'You do not have access to this area.');
                $status = 'draft'; $approvedBy = null;
            } else {
                abort_unless($this->access->reaches($actor, $owner, 'performance.goals.manage'), 403, 'You cannot set goals for this person.');
            }
        } elseif ($level === 'company') {
            abort_unless($this->access->tenantWide($actor, 'performance.goals.manage'), 403, 'Only company-wide goal managers can create company goals.');
        } else {
            abort_unless($actor->allows('performance.goals.manage'), 403, 'You do not have access to this area.');
            if (empty($d['department_id']) && ! $owner) throw ValidationException::withMessages(['department_id' => 'Choose the department this goal belongs to.']);
            if ($owner && ! $this->access->reaches($actor, $owner, 'performance.goals.manage')) abort(403, 'You cannot assign goals to this person.');
        }
        $parent = null;
        if (! empty($d['parent_uuid'])) {
            $parent = $this->access->visibleGoals($actor)->where('uuid', $d['parent_uuid'])->firstOrFail();
            if (self::LEVELS[$parent->level] >= self::LEVELS[$level]) throw ValidationException::withMessages(['parent_uuid' => 'A goal can only align to a goal at a higher level.']);
            if (in_array($parent->status, ['cancelled', 'rejected'], true)) throw ValidationException::withMessages(['parent_uuid' => 'That goal is not active.']);
        }
        $cycle = ! empty($d['cycle_uuid']) ? Cycle::byUuid($d['cycle_uuid']) : null;
        $kind = $d['kind'] ?? 'goal';
        $mode = $d['progress_mode'] ?? ($kind === 'kpi' ? 'metric' : 'rollup');
        if ($mode === 'metric' && ! isset($d['target_value'])) throw ValidationException::withMessages(['target_value' => 'A measurable goal needs a target value.']);
        $goal = DB::transaction(function () use ($actor, $d, $owner, $level, $status, $approvedBy, $parent, $cycle, $kind, $mode) {
            $goal = Goal::create([
                'level' => $level, 'kind' => $kind, 'parent_id' => $parent?->id, 'owner_employee_id' => $owner?->id, 'cycle_id' => $cycle?->id,
                'department_id' => $owner ? $this->access->departmentOf($owner) : ($d['department_id'] ?? null),
                'title' => $d['title'], 'description' => $d['description'] ?? null, 'weight' => Num::round(Num::in($d['weight'] ?? 100), 2),
                'start_date' => $d['start_date'], 'end_date' => $d['end_date'], 'status' => $status, 'visibility' => $d['visibility'] ?? ($level === 'individual' ? 'team' : 'company'),
                'progress_mode' => $mode, 'unit' => $d['unit'] ?? null, 'direction' => $d['direction'] ?? 'increase',
                'start_value' => isset($d['start_value']) ? Num::in($d['start_value']) : ($mode === 'metric' ? '0' : null), 'target_value' => isset($d['target_value']) ? Num::in($d['target_value']) : null,
                'current_value' => isset($d['current_value']) ? Num::in($d['current_value']) : null, 'created_by' => $actor->id,
                'approved_by' => $approvedBy, 'approved_at' => $approvedBy ? now() : null,
            ]);
            if ($mode === 'metric') $this->recalc($goal);
            Audit::record('goal.created', 'performance', 'goal:'.$goal->uuid, ['level' => $level]);

            return $goal;
        });
        if ($owner) $this->events->record('GoalCreated', ['employee' => $owner, 'subject' => ['performance_goal', $goal->id], 'summary' => 'Goal "'.$goal->title.'" was set', 'payload' => ['goal' => $goal->uuid, 'level' => $level]]);

        return $goal;
    }

    public function canEdit(User $u, Goal $g): bool
    {
        if ($g->owner_employee_id && $this->access->isSelf($u, $g->owner_employee_id)) return $u->allows('performance.self');
        if ($g->created_by === $u->id && $u->allows('performance.goals.manage')) return true;
        if (! $u->allows('performance.goals.manage')) return false;
        if (! $g->owner_employee_id) return $this->access->tenantWide($u, 'performance.goals.manage') || $g->created_by === $u->id;

        return $this->access->reaches($u, $g->owner_employee_id, 'performance.goals.manage');
    }

    public function update(User $actor, Goal $g, array $d): Goal
    {
        abort_unless($this->canEdit($actor, $g), 403, 'You cannot change this goal.');
        if (in_array($g->status, ['completed', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'This goal is closed.']);
        $fields = array_intersect_key($d, array_flip(['title', 'description', 'weight', 'start_date', 'end_date', 'visibility', 'unit', 'target_value', 'start_value']));
        foreach (['weight', 'target_value', 'start_value'] as $k) if (isset($fields[$k])) $fields[$k] = Num::in($fields[$k]);
        $selfOwned = $g->owner_employee_id && $this->access->isSelf($actor, $g->owner_employee_id);
        DB::transaction(function () use ($g, $fields, $selfOwned, $actor) {
            $g->fill($fields);
            if ($g->isDirty(['title', 'weight', 'target_value', 'start_value', 'start_date', 'end_date'])) {
                $g->version++;
                if ($g->status === 'active' && $selfOwned) { $g->status = 'pending_approval'; $g->approved_by = null; $g->approved_at = null; }
                Audit::record('goal.changed', 'performance', 'goal:'.$g->uuid, ['version' => $g->version]);
            }
            $g->save();
            $this->recalc($g);
        });

        return $g->refresh();
    }

    public function submit(User $actor, Goal $g): Goal
    {
        abort_unless($g->owner_employee_id && $this->access->isSelf($actor, $g->owner_employee_id), 403, 'Only the goal owner can submit it.');
        if (! in_array($g->status, ['draft', 'rejected'], true)) throw ValidationException::withMessages(['status' => 'Only draft or returned goals can be submitted.']);
        $g->update(['status' => 'pending_approval', 'decision_note' => null]);
        $this->routeApproval($g);
        Audit::record('goal.submitted', 'performance', 'goal:'.$g->uuid);

        return $g;
    }

    public function decide(User $actor, Goal $g, string $decision, ?string $note): Goal
    {
        abort_unless($actor->allows('performance.goals.approve'), 403, 'You do not have access to this area.');
        abort_unless($g->owner_employee_id && $this->access->reaches($actor, $g->owner_employee_id, 'performance.goals.approve'), 403, 'You cannot decide this goal.');
        abort_if($this->access->isSelf($actor, $g->owner_employee_id), 403, 'You cannot approve your own goal.');
        if ($g->status !== 'pending_approval') throw ValidationException::withMessages(['status' => 'This goal is not waiting for approval.']);
        $approve = $decision === 'approve';
        if (! $approve && ! $note) throw ValidationException::withMessages(['note' => 'Say why the goal is returned.']);
        $g->update(['status' => $approve ? 'active' : 'rejected', 'approved_by' => $approve ? $actor->id : null, 'approved_at' => $approve ? now() : null, 'decision_note' => $note ? mb_substr($note, 0, 500) : null]);
        app(TaskService::class)->completeBySource('performance', 'goal:'.$g->uuid);
        $owner = Employee::find($g->owner_employee_id);
        if ($owner && ($uid = $this->access->userIdOf($owner))) app(NotificationService::class)->send($uid, $approve ? 'Goal approved' : 'Goal returned', '"'.$g->title.'" was '.($approve ? 'approved.' : 'returned: '.$note), 'performance', '/performance/goals');
        Audit::record($approve ? 'goal.approved' : 'goal.returned', 'performance', 'goal:'.$g->uuid);

        return $g;
    }

    /** Creates one individual child goal per owner under a higher-level goal. Idempotent per (parent, owner). */
    public function cascade(User $actor, Goal $parent, array $employees, ?string $weight): array
    {
        abort_unless($actor->allows('performance.goals.manage'), 403, 'You do not have access to this area.');
        if ($parent->level === 'individual') throw ValidationException::withMessages(['goal' => 'Individual goals cannot be cascaded.']);
        if (! in_array($parent->status, ['active'], true)) throw ValidationException::withMessages(['goal' => 'Only active goals can be cascaded.']);
        $created = [];
        foreach ($employees as $owner) {
            abort_unless($this->access->reaches($actor, $owner, 'performance.goals.manage'), 403, 'You cannot assign goals to this person.');
            if (Goal::where('parent_id', $parent->id)->where('owner_employee_id', $owner->id)->exists()) continue;
            $created[] = $this->create($actor, ['level' => 'individual', 'kind' => $parent->kind === 'kpi' ? 'kpi' : 'goal', 'parent_uuid' => $parent->uuid, 'title' => $parent->title, 'description' => $parent->description,
                'weight' => $weight ?? '100', 'start_date' => $parent->start_date->toDateString(), 'end_date' => $parent->end_date->toDateString(), 'cycle_uuid' => $parent->cycle_id ? Cycle::find($parent->cycle_id)?->uuid : null,
                'progress_mode' => 'manual', 'unit' => $parent->unit, 'direction' => $parent->direction, 'visibility' => 'team'], $owner);
        }

        return $created;
    }

    public function addKeyResult(User $actor, Goal $g, array $d): KeyResult
    {
        abort_unless($this->canEdit($actor, $g), 403, 'You cannot change this goal.');
        if (in_array($g->status, ['completed', 'cancelled'], true)) throw ValidationException::withMessages(['status' => 'This goal is closed.']);
        if ($g->progress_mode !== 'rollup') throw ValidationException::withMessages(['goal' => 'Key results can only be added to goals that roll up progress.']);
        $kr = KeyResult::create(['goal_id' => $g->id, 'title' => $d['title'], 'weight' => Num::in($d['weight'] ?? 1), 'unit' => $d['unit'] ?? null, 'direction' => $d['direction'] ?? 'increase',
            'start_value' => Num::in($d['start_value'] ?? 0), 'target_value' => Num::in($d['target_value']), 'current_value' => isset($d['current_value']) ? Num::in($d['current_value']) : null]);
        $kr->progress = $kr->current_value === null ? '0.00' : Num::progress($kr->start_value, $kr->target_value, $kr->current_value);
        $kr->save();
        $this->recalc($g);
        Audit::record('goal.key_result_added', 'performance', 'goal:'.$g->uuid);

        return $kr;
    }

    /** Append-only check-in. Value updates a key result or a metric goal; progress updates a manual/rollup leaf goal. */
    public function checkin(User $actor, Goal $g, array $d): Checkin
    {
        abort_unless($this->canEdit($actor, $g), 403, 'You cannot check in on this goal.');
        if ($g->status !== 'active') throw ValidationException::withMessages(['status' => 'Only active goals accept check-ins.']);
        return DB::transaction(function () use ($actor, $g, $d) {
            $g = Goal::query()->whereKey($g->id)->lockForUpdate()->first();
            $before = Num::round(Num::in($g->progress), 2);
            $kr = null; $value = isset($d['value']) ? Num::in($d['value']) : null;
            if (! empty($d['key_result_uuid'])) {
                $kr = KeyResult::where('goal_id', $g->id)->where('uuid', $d['key_result_uuid'])->firstOrFail();
                if ($value === null) throw ValidationException::withMessages(['value' => 'Enter the current value of the key result.']);
                $kr->update(['current_value' => $value, 'progress' => Num::progress($kr->start_value, $kr->target_value, $value)]);
            } elseif ($g->progress_mode === 'metric') {
                if ($value === null) throw ValidationException::withMessages(['value' => 'Enter the current value.']);
                $g->current_value = $value; $g->save();
            } elseif (isset($d['progress'])) {
                $p = Num::in($d['progress']);
                if (Decimal::cmp($p, 0) < 0 || Decimal::cmp($p, 100) > 0) throw ValidationException::withMessages(['progress' => 'Progress must be between 0 and 100.']);
                if ($g->keyResults()->exists() || $g->children()->where('status', 'active')->exists()) throw ValidationException::withMessages(['progress' => 'Progress of this goal is calculated from its key results or aligned goals.']);
                $g->progress = Num::round($p, 2); $g->save();
            } else {
                throw ValidationException::withMessages(['value' => 'Provide a value or a progress percentage.']);
            }
            $this->recalc($g);
            $g->refresh();
            $c = Checkin::create(['goal_id' => $g->id, 'key_result_id' => $kr?->id, 'author_id' => $actor->id, 'value' => $value, 'progress_before' => $before, 'progress_after' => Num::round(Num::in($g->progress), 2),
                'confidence' => $d['confidence'] ?? 'on_track', 'note' => $d['note'] ?? null]);
            Audit::record('goal.checkin', 'performance', 'goal:'.$g->uuid, ['progress' => $c->progress_after]);

            return $c;
        });
    }

    public function complete(User $actor, Goal $g, bool $cancel = false): Goal
    {
        abort_unless($this->canEdit($actor, $g), 403, 'You cannot change this goal.');
        if ($g->status !== 'active') throw ValidationException::withMessages(['status' => 'Only active goals can be closed.']);
        $g->update(['status' => $cancel ? 'cancelled' : 'completed']);
        $this->recalc($g);
        Audit::record($cancel ? 'goal.cancelled' : 'goal.completed', 'performance', 'goal:'.$g->uuid);

        return $g;
    }

    /** Recomputes this goal's progress from key results / metric / aligned goals, then propagates to ancestors. All arithmetic is decimal. */
    public function recalc(Goal $g): void
    {
        $seen = [];
        while ($g && ! in_array($g->id, $seen, true)) {
            $seen[] = $g->id;
            $p = $this->compute($g);
            if ($p !== null && Num::round(Num::in($g->progress), 2) !== $p) { $g->progress = $p; $g->save(); }
            $g = $g->parent_id ? Goal::find($g->parent_id) : null;
        }
    }

    private function compute(Goal $g): ?string
    {
        if ($g->progress_mode === 'metric') return $g->current_value === null ? '0.00' : Num::progress($g->start_value, $g->target_value, $g->current_value);
        if ($g->progress_mode !== 'rollup') return null;
        $krs = $g->keyResults()->get();
        if ($krs->isNotEmpty()) return $this->weighted($krs->map(fn ($k) => [$k->weight, $k->progress]));
        $children = Goal::where('parent_id', $g->id)->where('status', 'active')->get();
        if ($children->isNotEmpty()) return $this->weighted($children->map(fn ($c) => [$c->weight, $c->progress]));

        return null;
    }

    /** Σ(weight × progress) / Σ(weight), scale 2 HALF_UP. */
    private function weighted($pairs): string
    {
        $w = Decimal::sum($pairs->map(fn ($p) => Num::in($p[0])));
        if ($w->isZero()) return '0.00';
        $sum = Decimal::sum($pairs->map(fn ($p) => Decimal::mul(Num::in($p[0]), Num::in($p[1]))));

        return Num::round(Decimal::div($sum, $w, 6), 2);
    }

    private function routeApproval(Goal $g): void
    {
        $owner = Employee::find($g->owner_employee_id);
        if (! $owner) return;
        $approver = app(ApproverResolver::class)->forEmployee($owner, 'goal', TenantClock::today());
        $ids = $approver && $approver->allows('performance.goals.approve') ? [$approver->id] : app(ApproverResolver::class)->hrUsers('goal');
        foreach (array_slice($ids, 0, 5) as $uid) {
            if ($uid === $this->access->userIdOf($owner)) continue;
            app(TaskService::class)->createOnce(['title' => 'Approve goal', 'description' => '"'.$g->title.'" is waiting for your approval.', 'assignee_id' => $uid, 'source_module' => 'performance', 'source_entity' => 'goal:'.$g->uuid,
                'action' => '/performance/goals', 'status' => 'open', 'priority' => 'normal']);
        }
    }

    /** Serializes a goal for the API (no internal ids). */
    public function present(Goal $g, bool $detail = false): array
    {
        $owner = $g->owner_employee_id ? Employee::with('person')->find($g->owner_employee_id) : null;
        $row = ['uuid' => $g->uuid, 'level' => $g->level, 'kind' => $g->kind, 'title' => $g->title, 'description' => $g->description, 'weight' => Num::round(Num::in($g->weight), 2),
            'status' => $g->status, 'visibility' => $g->visibility, 'progress_mode' => $g->progress_mode, 'progress' => Num::round(Num::in($g->progress), 2), 'start_date' => $g->start_date->toDateString(), 'end_date' => $g->end_date->toDateString(),
            'unit' => $g->unit, 'direction' => $g->direction, 'start_value' => $g->start_value, 'target_value' => $g->target_value, 'current_value' => $g->current_value, 'version' => $g->version,
            'parent_uuid' => $g->parent_id ? Goal::whereKey($g->parent_id)->value('uuid') : null,
            'owner' => $owner ? ['uuid' => $owner->uuid, 'name' => trim(($owner->person->legal_first_name ?? '').' '.($owner->person->legal_last_name ?? ''))] : null, 'decision_note' => $g->decision_note];
        if ($detail) {
            $row['key_results'] = $g->keyResults()->get()->map(fn ($k) => ['uuid' => $k->uuid, 'title' => $k->title, 'weight' => Num::round(Num::in($k->weight), 2), 'unit' => $k->unit, 'start_value' => $k->start_value, 'target_value' => $k->target_value, 'current_value' => $k->current_value, 'progress' => Num::round(Num::in($k->progress), 2)])->all();
            $row['checkins'] = Checkin::where('goal_id', $g->id)->latest('id')->limit(50)->get()->map(fn ($c) => ['uuid' => $c->uuid, 'value' => $c->value, 'progress_before' => Num::round(Num::in($c->progress_before), 2), 'progress_after' => Num::round(Num::in($c->progress_after), 2), 'confidence' => $c->confidence, 'note' => $c->note, 'at' => $c->created_at?->toIso8601String()])->all();
        }

        return $row;
    }
}
