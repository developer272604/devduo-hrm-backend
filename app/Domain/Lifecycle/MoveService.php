<?php

namespace App\Domain\Lifecycle;

use App\Domain\Access\Classification;
use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Transfers\PeopleEventListener;
use App\Domain\Lifecycle\Contracts\MoveGuard;
use App\Domain\Lifecycle\Models\Move;
use App\Domain\People\Models\{Assignment, Employee};
use App\Domain\People\Services\{AssignmentService, ReportingService};
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Internal moves (transfer, promotion, manager change, location change) as effective-dated requests with maker-checker approval.
 * Applying goes through People's AssignmentService / ReportingService, which emit EmployeeTransferred / EmployeePromoted so Leave
 * (and later Payroll) consume the change through their existing listeners. Nothing here edits assignments directly.
 */
final class MoveService
{
    public const KINDS = ['transfer', 'promotion', 'manager_change', 'location_change'];

    private const ALLOWED = [
        'transfer' => ['legal_entity_id', 'business_unit_id', 'department_id', 'team_id', 'cost_center_id', 'location_id', 'employee_type_id', 'work_mode', 'manager_uuid'],
        'promotion' => ['designation_id', 'position_id', 'grade_id', 'level_id'],
        'location_change' => ['location_id', 'legal_entity_id', 'work_mode'],
        'manager_change' => ['manager_uuid'],
    ];

    public function __construct(private AssignmentService $assignments, private ReportingService $reporting, private DomainEvents $events, private CaseService $cases) {}

    public function request(User $actor, Employee $employee, array $d): Move
    {
        abort_unless($actor->allows('lifecycle.moves.manage') && app(DataScope::class)->canAccess($actor, $employee, 'lifecycle.moves.manage'), 404);
        abort_unless($employee->isWorking(), 422, 'Only employees who are currently working can be moved.');
        $changes = array_filter(array_intersect_key($d['changes'], array_flip(self::ALLOWED[$d['kind']])), fn ($v) => $v !== null && $v !== '');
        abort_if(! $changes, 422, 'Give at least one change that fits this kind of move.');
        abort_if(isset($changes['manager_uuid']) && ! Employee::where('uuid', $changes['manager_uuid'])->exists(), 422, 'Choose a manager in this workspace.');
        abort_if($d['effective_date'] < $employee->joined_on->toDateString(), 422, 'A move cannot start before the employee joined.');
        $move = Move::create(['employee_id' => $employee->id, 'kind' => $d['kind'], 'changes' => $changes, 'effective_date' => $d['effective_date'], 'reason' => $d['reason'] ?? null, 'status' => 'pending', 'requested_by' => $actor->id]);
        Audit::record('move.requested', 'lifecycle', $move->uuid, ['employee' => $employee->uuid, 'kind' => $move->kind, 'effective_date' => $move->effective_date->toDateString()]);

        return $move;
    }

    public function decide(User $actor, Move $move, string $decision, ?string $note): Move
    {
        return DB::transaction(function () use ($actor, $move, $decision, $note) {
            $move = Move::whereKey($move->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->allows('lifecycle.moves.approve') && app(DataScope::class)->canAccess($actor, $move->employee_id, 'lifecycle.moves.approve'), 404);
            abort_unless($move->status === 'pending', 409, 'This request was already decided.');
            abort_if($move->requested_by === $actor->id, 403, 'A different authorized person must approve this request.');
            $move->update(['status' => $decision === 'approve' ? 'approved' : 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
            Audit::record('move.'.($decision === 'approve' ? 'approved' : 'rejected'), 'lifecycle', $move->uuid, ['note' => $note]);
            if ($decision === 'approve' && $move->effective_date->toDateString() <= TenantClock::today()) {
                $this->apply($move);
            }

            return $move->refresh();
        });
    }

    public function cancel(User $actor, Move $move): Move
    {
        abort_unless($move->requested_by === $actor->id || $actor->allows('lifecycle.moves.approve'), 404);
        abort_unless(in_array($move->status, ['pending', 'approved'], true), 409, 'Only an open request can be cancelled.');
        $move->update(['status' => 'cancelled']);
        Audit::record('move.cancelled', 'lifecycle', $move->uuid);

        return $move;
    }

    /** Idempotent: only an approved move is applied, once. Used on approval and by the daily job for future-dated moves. */
    public function apply(Move $move): Move
    {
        return DB::transaction(function () use ($move) {
            $move = Move::whereKey($move->id)->lockForUpdate()->firstOrFail();
            if ($move->status !== 'approved') return $move;
            $employee = Employee::findOrFail($move->employee_id);
            $date = $move->effective_date->toDateString();
            app(MoveGuard::class)->assertMovable($employee, $date);
            $changes = $move->changes;
            $manager = null;
            if (isset($changes['manager_uuid'])) {
                $manager = Employee::where('uuid', $changes['manager_uuid'])->firstOrFail();
                unset($changes['manager_uuid']);
            }
            $meta = ['change_type' => $move->kind === 'promotion' ? 'promotion' : 'transfer', 'reason' => $move->reason, 'source' => 'lifecycle', 'approved_by' => $move->decided_by, 'approval_ref' => $move->uuid];
            if ($changes) {
                $this->assignments->change($employee, $changes, $date, $meta);
            }
            if ($manager) {
                $this->reporting->assign($employee, $manager, 'primary', $date, $move->reason, 'lifecycle');
            }
            $move->update(['status' => 'applied', 'applied_at' => now()]);
            $this->events->record('LifecycleMoveApplied', ['employee' => $employee, 'subject' => ['lifecycle_move', $move->id], 'effective_date' => $date, 'classification' => Classification::HR_CONFIDENTIAL,
                'summary' => ucfirst(str_replace('_', ' ', $move->kind)).' applied, effective '.$date.'.', 'payload' => ['move' => $move->uuid, 'kind' => $move->kind, 'fields' => array_keys($move->changes)]]);
            Audit::record('move.applied', 'lifecycle', $move->uuid, ['employee' => $employee->uuid]);
            $this->cases->generate($move->kind === 'promotion' ? 'promotion' : 'transfer', $employee, $date, ['source' => 'move', 'move_id' => $move->id]);

            return $move->refresh();
        });
    }

    /** Cross-module impact preview: what the receiving modules will review. Nothing is applied. */
    public function impact(Move $move): array
    {
        $fields = array_keys($move->changes);
        $out = [];
        if (array_intersect(PeopleEventListener::POLICY_FIELDS, $fields)) $out[] = ['module' => 'leave', 'note' => 'A leave transfer preview will be prepared for HR to review.'];
        if (array_intersect(['legal_entity_id', 'grade_id', 'level_id', 'position_id', 'cost_center_id'], $fields)) $out[] = ['module' => 'payroll', 'note' => 'Compensation and payroll group assignment need review from the effective date.'];
        if (array_intersect(['location_id', 'work_mode'], $fields)) $out[] = ['module' => 'attendance', 'note' => 'Shift, holiday calendar and attendance rules may change from the effective date.'];
        if (in_array('manager_uuid', $fields, true)) $out[] = ['module' => 'approvals', 'note' => 'Approval routing follows the new manager from the effective date.'];
        if ($move->effective_date->toDateString() < TenantClock::today()) $out[] = ['module' => 'periods', 'note' => 'Retroactive: closed attendance or payroll periods may need correction.'];

        return $out;
    }

    public function present(Move $m, ?User $viewer = null): array
    {
        $e = Employee::with('person')->find($m->employee_id);
        $canDecide = $m->status === 'pending'
            && $viewer !== null
            && $viewer->id !== $m->requested_by
            && $viewer->allows('lifecycle.moves.approve')
            && app(DataScope::class)->canAccess($viewer, $m->employee_id, 'lifecycle.moves.approve');

        return ['uuid' => $m->uuid, 'kind' => $m->kind, 'status' => $m->status, 'effective_date' => $m->effective_date->toDateString(), 'reason' => $m->reason, 'changes' => $m->changes,
            'employee' => ['uuid' => $e->uuid, 'name' => $e->person?->display_name], 'employee_uuid' => $e->uuid, 'employee_name' => $e->person?->display_name,
            'requested_by' => $m->requested_by, 'decided_by' => $m->decided_by, 'can_decide' => $canDecide,
            'applied_at' => $m->applied_at?->toIso8601String(), 'impact' => $this->impact($m)];
    }
}
