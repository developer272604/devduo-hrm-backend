<?php

namespace App\Domain\Performance;

use App\Domain\Audit\Audit;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Performance\Models\{DevAction, DevPlan, Feedback, Goal, OneOnOne};
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Continuous feedback, 1:1 notes (private by default) and development plans. Privacy rules live here, not in controllers. */
final class CollaborationService
{
    public function __construct(private PerformanceAccess $access) {}

    // ---- Feedback ---------------------------------------------------------------------------------------------------------------

    public function giveFeedback(User $actor, Employee $to, array $d): Feedback
    {
        abort_unless($actor->allows('performance.self'), 403, 'You do not have access to this area.');
        if ($this->access->isSelf($actor, $to)) throw ValidationException::withMessages(['employee_uuid' => 'You cannot send feedback to yourself.']);
        if (! $to->isWorking()) throw ValidationException::withMessages(['employee_uuid' => 'Feedback can only be sent to active employees.']);
        $f = Feedback::create(['to_employee_id' => $to->id, 'from_user_id' => $actor->id, 'kind' => $d['kind'] ?? 'feedback', 'visibility' => $d['visibility'] ?? 'recipient', 'anonymous' => false, 'message' => $d['message']]);
        if ($uid = $this->access->userIdOf($to)) app(NotificationService::class)->send($uid, ($f->kind === 'recognition' ? 'You received recognition' : 'You received feedback'), 'Open Performance to read it.', 'performance', '/performance/feedback');
        Audit::record('feedback.given', 'performance', 'employee:'.$to->uuid, ['kind' => $f->kind]);

        return $f;
    }

    /** Feedback about an employee as the viewer may see it: recipient and giver always; managers only when the giver shared it with the manager. Never HR by default. */
    public function feedbackAbout(User $viewer, Employee $subject)
    {
        $self = $this->access->isSelf($viewer, $subject);
        $manager = ! $self && $viewer->allows('performance.view') && $this->access->isManagerOf($viewer, $subject);
        abort_unless($self || $manager || Feedback::where('to_employee_id', $subject->id)->where('from_user_id', $viewer->id)->exists(), 403, 'You cannot read feedback for this person.');

        return Feedback::where('to_employee_id', $subject->id)->where(function ($q) use ($viewer, $self, $manager) {
            $q->where('from_user_id', $viewer->id);
            if ($self) $q->orWhereRaw('1 = 1');
            elseif ($manager) $q->orWhere('visibility', 'recipient_manager');
        })->latest('id');
    }

    public function presentFeedback(Feedback $f, User $viewer): array
    {
        $mine = $f->from_user_id === $viewer->id;
        $reveal = $mine || $viewer->allows('performance.feedback.reveal') || ! $f->anonymous;

        return ['uuid' => $f->uuid, 'kind' => $f->kind, 'visibility' => $f->visibility, 'message' => $f->message, 'anonymous' => $f->anonymous, 'from' => $reveal ? User::whereKey($f->from_user_id)->value('name') : null, 'mine' => $mine, 'at' => $f->created_at?->toIso8601String()];
    }

    // ---- 1:1 notes --------------------------------------------------------------------------------------------------------------

    /** Author is the employee's manager (in the reporting chain) or the employee (own journal). Private notes never leave the author. */
    public function recordOneOnOne(User $actor, Employee $employee, array $d): OneOnOne
    {
        abort_unless($actor->allows('performance.self'), 403, 'You do not have access to this area.');
        abort_unless($this->access->isSelf($actor, $employee) || $this->access->isManagerOf($actor, $employee), 403, 'Only the employee or their manager can record 1:1 notes.');
        $n = OneOnOne::create(['employee_id' => $employee->id, 'author_id' => $actor->id, 'held_on' => $d['held_on'], 'shared_notes' => $d['shared_notes'] ?? null, 'private_notes' => $d['private_notes'] ?? null, 'action_items' => $d['action_items'] ?? null]);
        Audit::record('one_on_one.recorded', 'performance', 'employee:'.$employee->uuid);

        return $n;
    }

    /** Participants: the employee sees shared notes of managers' 1:1s about them; authors see their own private notes. No one else (HR included). */
    public function oneOnOnes(User $viewer, Employee $employee)
    {
        $self = $this->access->isSelf($viewer, $employee);
        $manager = $viewer->allows('performance.self') && $this->access->isManagerOf($viewer, $employee);
        abort_unless($self || $manager, 403, 'You cannot read 1:1 notes for this person.');

        return OneOnOne::where('employee_id', $employee->id)->where(function ($q) use ($viewer, $self) {
            $q->where('author_id', $viewer->id);
            if ($self) $q->orWhereNotNull('shared_notes');
        })->orderByDesc('held_on')->orderByDesc('id');
    }

    public function presentOneOnOne(OneOnOne $n, User $viewer): array
    {
        $mine = $n->author_id === $viewer->id;

        return ['uuid' => $n->uuid, 'held_on' => $n->held_on->toDateString(), 'shared_notes' => $n->shared_notes, 'private_notes' => $mine ? $n->private_notes : null, 'action_items' => $n->action_items, 'mine' => $mine];
    }

    // ---- Development plans ------------------------------------------------------------------------------------------------------

    public function createPlan(User $actor, Employee $employee, array $d): DevPlan
    {
        $self = $this->access->isSelf($actor, $employee);
        abort_unless($self ? $actor->allows('performance.self') : $this->access->reaches($actor, $employee, 'performance.goals.manage'), 403, 'You cannot create a development plan for this person.');
        $goal = ! empty($d['goal_uuid']) ? $this->access->visibleGoals($actor)->where('uuid', $d['goal_uuid'])->firstOrFail() : null;
        return DB::transaction(function () use ($actor, $employee, $d, $goal) {
            $p = DevPlan::create(['employee_id' => $employee->id, 'title' => $d['title'], 'description' => $d['description'] ?? null, 'status' => 'active', 'target_date' => $d['target_date'] ?? null, 'goal_id' => $goal?->id, 'created_by' => $actor->id]);
            foreach ($d['actions'] ?? [] as $a) $this->addAction($p, $a);
            Audit::record('development_plan.created', 'performance', 'employee:'.$employee->uuid);

            return $p;
        });
    }

    public function addAction(DevPlan $p, array $a): DevAction
    {
        if (! empty($a['skill_id']) && ! DB::table('skills')->where('tenant_id', $p->tenant_id)->where('id', $a['skill_id'])->exists()) throw ValidationException::withMessages(['skill_id' => 'Unknown skill.']);

        return DevAction::create(['plan_id' => $p->id, 'title' => $a['title'], 'skill_id' => $a['skill_id'] ?? null, 'learning_ref' => $a['learning_ref'] ?? null, 'due_on' => $a['due_on'] ?? null]);
    }

    public function canManagePlan(User $u, DevPlan $p): bool
    {
        return $this->access->isSelf($u, $p->employee_id) ? $u->allows('performance.self') : $this->access->reaches($u, $p->employee_id, 'performance.goals.manage');
    }

    public function plans(User $u)
    {
        return DevPlan::query()->where(function ($q) use ($u) {
            $own = $this->access->own($u);
            $q->whereIn('employee_id', $own ?: [0]);
            if ($u->allows('performance.view')) $q->orWhereIn('employee_id', $this->access->reachIds($u, 'performance.view'));
        })->latest('id');
    }

    public function presentPlan(DevPlan $p): array
    {
        $e = Employee::with('person')->find($p->employee_id);

        return ['uuid' => $p->uuid, 'title' => $p->title, 'description' => $p->description, 'status' => $p->status, 'target_date' => $p->target_date?->toDateString(), 'goal_uuid' => $p->goal_id ? Goal::whereKey($p->goal_id)->value('uuid') : null,
            'employee' => ['uuid' => $e->uuid, 'name' => trim(($e->person->legal_first_name ?? '').' '.($e->person->legal_last_name ?? ''))],
            'actions' => $p->actions()->get()->map(fn ($a) => ['uuid' => $a->uuid, 'title' => $a->title, 'skill_id' => $a->skill_id, 'learning_ref' => $a->learning_ref, 'due_on' => $a->due_on?->toDateString(), 'status' => $a->status])->all()];
    }
}
