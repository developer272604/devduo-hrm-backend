<?php

namespace App\Domain\Payroll\Compensation;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Payroll\Models\CompensationRevision;
use App\Domain\Payroll\Models\CompensationRevisionBatch;
use App\Domain\Payroll\Models\EmployeeCompensation;
use App\Domain\Payroll\Models\SalaryStructure;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\PayrollEvents;
use App\Domain\Payroll\Support\Rounding;
use App\Domain\People\Models\Employee;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Support\RequestFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Compensation revisions (increment, promotion, ...): effective-dated, future or backdated, maker-checker, with an impact preview that never mutates. */
final class RevisionService
{
    public function __construct(private CompensationService $compensations, private CompensationCalculator $calc, private CompensationResolverImpl $resolver) {}

    private function oldFor(Employee $employee, string $date): EmployeeCompensation
    {
        return EmployeeCompensation::where('employee_id', $employee->id)->where('status', 'approved')->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))->orderByDesc('effective_from')->first()
            ?? throw ValidationException::withMessages(['effective_date' => 'The employee has no approved compensation on that date. Create the initial compensation first.']);
    }

    /** Old vs new figures. Never writes. */
    public function preview(User $actor, array $data): array
    {
        abort_unless($actor->allows('payroll.compensation.manage'), 403);
        $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.compensation.manage')->where('uuid', $data['employee_uuid'])->firstOrFail();
        $old = $this->oldFor($employee, $data['effective_date']);
        $group = $old->group;
        $structure = isset($data['structure_uuid']) ? SalaryStructure::where('uuid', $data['structure_uuid'])->where('status', 'active')->firstOrFail() : $old->structure;
        $built = $this->calc->build($data['lines'], $group, $data['effective_date'], null, $structure);
        $oldGross = $old->gross_reference ?? '0';
        $newGross = $built['summary']['gross_reference'];
        $delta = (string) Decimal::sub($newGross, $oldGross);
        $pct = Decimal::isZero($oldGross) ? null : Rounding::apply(Decimal::mul(Decimal::div($delta, $oldGross, 8), 100), 2);

        return ['employee_uuid' => $employee->uuid, 'currency' => $group->currency, 'effective_date' => $data['effective_date'], 'backdated' => $data['effective_date'] < now()->toDateString(),
            'current' => ['gross_reference' => $old->gross_reference, 'annual_compensation' => $old->annual_compensation], 'proposed' => ['gross_reference' => $newGross, 'annual_compensation' => $built['summary']['annual_compensation']],
            'change' => ['gross_reference' => $delta, 'percent' => $pct, 'annual' => (string) Decimal::sub($built['summary']['annual_compensation'], $old->annual_compensation ?? '0')],
            'retro_review_required' => $data['effective_date'] < now()->toDateString(), 'persisted' => false];
    }

    public function propose(User $actor, array $data): CompensationRevision
    {
        abort_unless($actor->allows('payroll.compensation.manage'), 403);

        return DB::transaction(function () use ($actor, $data) {
            $hash = RequestFingerprint::make($data);
            $existing = CompensationRevision::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->initiator_id === $actor->id && hash_equals((string) $existing->request_hash, $hash), 409, 'Request key already belongs to another operation.');

                return $existing;
            }
            $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.compensation.manage')->where('uuid', $data['employee_uuid'])->lockForUpdate()->firstOrFail();
            $old = $this->oldFor($employee, $data['effective_date']);
            if (CompensationRevision::where('employee_id', $employee->id)->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['employee_uuid' => 'This employee already has a revision awaiting approval.']);
            }
            $preview = $this->preview($actor, $data);
            $new = $this->compensations->create($actor, [
                'employee_uuid' => $employee->uuid, 'group_uuid' => $old->group->uuid, 'structure_uuid' => $data['structure_uuid'] ?? $old->structure?->uuid, 'effective_from' => $data['effective_date'],
                'reason' => $data['reason'], 'idempotency_key' => $data['idempotency_key'], 'lines' => $data['lines'], 'source' => 'revision', 'supersedes_id' => $old->id,
            ], false);
            $rev = CompensationRevision::create(['employee_id' => $employee->id, 'type' => $data['type'], 'status' => 'pending', 'effective_date' => $data['effective_date'], 'old_compensation_id' => $old->id,
                'new_compensation_id' => $new->id, 'reason' => $data['reason'], 'comments' => $data['comments'] ?? null, 'initiator_id' => $actor->id, 'preview' => $preview,
                'letter_reference' => $data['letter_reference'] ?? null, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash]);
            $this->compensations->notify($actor, $employee, 'revision:'.$rev->uuid, 'Review compensation revision');
            Audit::record('revision.proposed', 'payroll', $rev->uuid, ['employee_uuid' => $employee->uuid, 'type' => $data['type']]);

            return $rev;
        });
    }

    public function decide(User $actor, string $uuid, string $decision): CompensationRevision
    {
        abort_unless($actor->allows('payroll.compensation.approve'), 403);
        $initial = CompensationRevision::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($actor, $initial->employee_id, 'payroll.compensation.approve'), 404);

        return DB::transaction(function () use ($actor, $initial, $decision) {
            Employee::whereKey($initial->employee_id)->lockForUpdate()->firstOrFail();
            $rev = CompensationRevision::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            abort_if($rev->initiator_id === $actor->id, 403, 'A different authorized person must review this revision.');
            abort_unless($rev->status === 'pending', 409, 'This revision has already been reviewed.');
            $new = EmployeeCompensation::whereKey($rev->new_compensation_id)->lockForUpdate()->firstOrFail();
            $this->compensations->settle($new, $decision, $actor, true);
            $rev->update(['status' => $decision === 'approved' ? 'applied' : 'rejected', 'approver_id' => $actor->id, 'decided_at' => now()]);
            app(TaskService::class)->completeBySource('payroll', 'revision:'.$rev->uuid);
            if ($decision === 'approved') {
                app(DomainEvents::class)->record(PayrollEvents::COMPENSATION_REVISED, ['employee' => $rev->employee_id, 'summary' => 'Compensation revised', 'effective_date' => substr((string) $rev->effective_date, 0, 10),
                    'actor_id' => $actor->id, 'payload' => ['revision_uuid' => $rev->uuid, 'type' => $rev->type, 'backdated' => substr((string) $rev->effective_date, 0, 10) < now()->toDateString()]]);
            }
            $this->syncBatch($rev, $actor);
            Audit::record('revision.'.$decision, 'payroll', $rev->uuid);

            return $rev;
        });
    }

    public function cancel(User $actor, string $uuid): CompensationRevision
    {
        return DB::transaction(function () use ($actor, $uuid) {
            $rev = CompensationRevision::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($rev->initiator_id === $actor->id, 403, 'Only the initiator can cancel a revision.');
            abort_unless($rev->status === 'pending', 409, 'Only a pending revision can be cancelled.');
            EmployeeCompensation::whereKey($rev->new_compensation_id)->update(['status' => 'cancelled']);
            $rev->update(['status' => 'cancelled']);
            app(TaskService::class)->completeBySource('payroll', 'revision:'.$rev->uuid);
            $this->syncBatch($rev, $actor);
            Audit::record('revision.cancelled', 'payroll', $rev->uuid);

            return $rev;
        });
    }

    public function present(CompensationRevision $r, bool $reveal): array
    {
        $r->loadMissing(['employee.person']);
        $oldC = EmployeeCompensation::find($r->old_compensation_id);
        $newC = EmployeeCompensation::find($r->new_compensation_id);

        return ['uuid' => $r->uuid, 'employee_uuid' => $r->employee->uuid, 'employee_name' => $r->employee->person->display_name, 'type' => $r->type, 'status' => $r->status,
            'effective_date' => substr((string) $r->effective_date, 0, 10), 'reason' => $r->reason, 'comments' => $r->comments, 'letter_reference' => $r->letter_reference,
            'old_compensation_uuid' => $oldC?->uuid, 'new_compensation_uuid' => $newC?->uuid, 'can_review' => auth()->id() !== $r->initiator_id && $r->status === 'pending',
            'backdated' => substr((string) $r->effective_date, 0, 10) < substr((string) $r->created_at, 0, 10), 'masked' => ! $reveal, 'preview' => $reveal ? $r->preview : null];
    }

    private function syncBatch(CompensationRevision $revision, User $actor): void
    {
        if (! $revision->batch_id) {
            return;
        }
        $batch = CompensationRevisionBatch::query()->whereKey($revision->batch_id)->lockForUpdate()->first();
        if (! $batch || $batch->status !== 'submitted') {
            return;
        }
        $rows = CompensationRevision::query()->where('batch_id', $batch->id)->get(['status']);
        if ($rows->contains(fn ($row) => $row->status === 'pending')) {
            return;
        }
        $applied = $rows->where('status', 'applied')->count();
        $batch->update(['status' => $applied === $rows->count() && (int) $batch->employees_failed === 0 ? 'applied' : ($applied > 0 ? 'partially_applied' : 'cancelled'),
            'approved_by' => $applied > 0 ? $actor->id : null, 'approved_at' => $applied > 0 ? now() : null]);
    }
}
