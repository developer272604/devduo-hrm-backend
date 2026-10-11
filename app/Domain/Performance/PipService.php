<?php

namespace App\Domain\Performance;

use App\Domain\Access\Classification;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\Performance\Models\{Pip, PipCheckpoint};
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Confidential performance improvement plans. HR (performance.pip.manage) writes; the employee reads their own once active. Every read and change is audited. No automated outcomes. */
final class PipService
{
    public function __construct(private PerformanceAccess $access, private DomainEvents $events) {}

    public function create(User $actor, Employee $employee, array $d): Pip
    {
        abort_unless($actor->allows('performance.pip.manage'), 403, 'You do not have access to this area.');
        abort_unless($this->access->reaches($actor, $employee, 'performance.pip.manage'), 403, 'You cannot manage a plan for this person.');
        abort_if($this->access->isSelf($actor, $employee), 403, 'You cannot create a plan for yourself.');
        if (Pip::where('employee_id', $employee->id)->whereIn('status', ['draft', 'active'])->exists()) throw ValidationException::withMessages(['employee_uuid' => 'This person already has an open improvement plan.']);
        if ($d['end_date'] < $d['start_date']) throw ValidationException::withMessages(['end_date' => 'The end date must be after the start date.']);
        return DB::transaction(function () use ($actor, $employee, $d) {
            $p = Pip::create(['employee_id' => $employee->id, 'manager_employee_id' => $this->access->managerOf($employee)?->id, 'reason' => $d['reason'], 'support_plan' => $d['support_plan'] ?? null, 'start_date' => $d['start_date'], 'end_date' => $d['end_date'], 'created_by' => $actor->id]);
            foreach ($d['checkpoints'] ?? [] as $c) {
                if ($c['due_on'] < $d['start_date'] || $c['due_on'] > $d['end_date']) throw ValidationException::withMessages(['checkpoints' => 'Checkpoints must fall inside the plan period.']);
                PipCheckpoint::create(['pip_id' => $p->id, 'due_on' => $c['due_on'], 'expectation' => $c['expectation']]);
            }
            Audit::record('pip.created', 'performance', 'pip:'.$p->uuid, ['employee' => $employee->uuid]);

            return $p;
        });
    }

    public function activate(User $actor, Pip $p): Pip
    {
        $this->guard($actor, $p);
        if ($p->status !== 'draft') throw ValidationException::withMessages(['status' => 'Only draft plans can be activated.']);
        if (! $p->checkpoints()->exists()) throw ValidationException::withMessages(['checkpoints' => 'Add at least one checkpoint before activating.']);
        $p->update(['status' => 'active']);
        $e = Employee::find($p->employee_id);
        if ($uid = $this->access->userIdOf($e)) app(NotificationService::class)->send($uid, 'A performance improvement plan was shared with you', 'Open Performance to read the plan and its checkpoints.', 'performance', '/performance/pip');
        $this->events->record('PerformancePlanActivated', ['employee' => $e, 'subject' => ['performance_pip', $p->id], 'summary' => 'An improvement plan was started', 'classification' => Classification::HIGHLY_SENSITIVE]);
        Audit::record('pip.activated', 'performance', 'pip:'.$p->uuid);

        return $p;
    }

    public function recordCheckpoint(User $actor, Pip $p, PipCheckpoint $c, string $status, ?string $note): PipCheckpoint
    {
        $this->guard($actor, $p);
        if ($p->status !== 'active') throw ValidationException::withMessages(['status' => 'The plan is not active.']);
        $c->update(['status' => $status, 'note' => $note, 'recorded_by' => $actor->id, 'recorded_at' => now()]);
        Audit::record('pip.checkpoint_recorded', 'performance', 'pip:'.$p->uuid, ['status' => $status]);

        return $c;
    }

    public function complete(User $actor, Pip $p, string $outcome, string $note): Pip
    {
        $this->guard($actor, $p);
        if ($p->status !== 'active') throw ValidationException::withMessages(['status' => 'Only active plans can be completed.']);
        if ($p->checkpoints()->where('status', 'pending')->exists() && $outcome === 'success') throw ValidationException::withMessages(['status' => 'Record every checkpoint before closing the plan as successful.']);
        $p->update(['status' => $outcome === 'success' ? 'completed_success' : ($outcome === 'cancelled' ? 'cancelled' : 'completed_unsuccessful'), 'outcome_note' => $note]);
        Audit::record('pip.completed', 'performance', 'pip:'.$p->uuid, ['outcome' => $outcome]);

        return $p;
    }

    private function guard(User $actor, Pip $p): void
    {
        abort_unless($actor->allows('performance.pip.manage') && $this->access->reaches($actor, $p->employee_id, 'performance.pip.manage'), 403, 'You cannot manage this plan.');
    }

    /** Read access: HR in scope, or the employee once the plan is active. Reads are audited. */
    public function canRead(User $u, Pip $p): bool
    {
        if ($this->access->isSelf($u, $p->employee_id)) return $p->status !== 'draft';

        return $u->allows('performance.pip.manage') && $this->access->reaches($u, $p->employee_id, 'performance.pip.manage');
    }

    public function present(Pip $p, bool $audit = true): array
    {
        $e = Employee::with('person')->find($p->employee_id);
        if ($audit) Audit::record('pip.viewed', 'performance', 'pip:'.$p->uuid);

        return ['uuid' => $p->uuid, 'status' => $p->status, 'employee' => ['uuid' => $e->uuid, 'name' => trim(($e->person->legal_first_name ?? '').' '.($e->person->legal_last_name ?? ''))], 'reason' => $p->reason, 'support_plan' => $p->support_plan,
            'start_date' => $p->start_date->toDateString(), 'end_date' => $p->end_date->toDateString(), 'outcome_note' => $p->outcome_note,
            'checkpoints' => $p->checkpoints()->get()->map(fn ($c) => ['uuid' => $c->uuid, 'due_on' => $c->due_on->toDateString(), 'expectation' => $c->expectation, 'status' => $c->status, 'note' => $c->note, 'recorded_at' => $c->recorded_at?->toIso8601String()])->all()];
    }
}
