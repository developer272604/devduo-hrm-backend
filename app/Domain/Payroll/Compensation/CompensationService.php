<?php

namespace App\Domain\Payroll\Compensation;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;
use App\Domain\Payroll\Models\{CompensationLine, EmployeeCompensation, PayrollGroup, SalaryStructure};
use App\Domain\Payroll\Support\PayrollEvents;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Support\RequestFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Maker-checker compensation records. Approved history is never overwritten: revisions end the previous segment the day before. */
final class CompensationService
{
    public function __construct(private CompensationCalculator $calc) {}

    /** @param array<string,mixed> $data employee_uuid, group_uuid?, structure_uuid?, effective_from, effective_to?, reason, idempotency_key, lines[], source?, supersedes_id? */
    public function create(User $actor, array $data, bool $notify = true): EmployeeCompensation
    {
        abort_unless($actor->allows('payroll.compensation.manage'), 403);
        return DB::transaction(function () use ($actor, $data, $notify) {
            $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.compensation.manage')->where('uuid', $data['employee_uuid'])->lockForUpdate()->firstOrFail();
            $existing = EmployeeCompensation::where('idempotency_key', $data['idempotency_key'])->first();
            $hash = RequestFingerprint::make(array_diff_key($data, ['supersedes_id' => 1]));
            if ($existing) {
                abort_unless($existing->employee_id === $employee->id && $existing->created_by === $actor->id && hash_equals($existing->request_hash, $hash), 409, 'Request key already belongs to another operation.');
                return $existing;
            }
            $group = isset($data['group_uuid'])
                ? PayrollGroup::where('uuid', $data['group_uuid'])->where('active', true)->firstOrFail()
                : PayrollGroup::whereKey(app(\App\Domain\Payroll\Groups\GroupResolver::class)->groupIdFor($employee->id, $data['effective_from']) ?? 0)->first() ?? $this->invalid('group_uuid', 'The employee has no payroll group on the effective date. Assign one first or pass group_uuid.');
            $assignment = $employee->assignmentOn($data['effective_from']);
            if (! $assignment || $assignment->legal_entity_id !== $group->legal_entity_id) $this->invalid('group_uuid', 'Payroll group must match the employee legal entity on the effective date.');
            if ($data['effective_from'] < ($employee->employment_start_date ?? $employee->joined_on)->format('Y-m-d')) $this->invalid('effective_from', 'Compensation cannot start before employment.');
            if ($employee->separated_on && $data['effective_from'] > $employee->separated_on->format('Y-m-d')) $this->invalid('effective_from', 'Compensation cannot start after separation.');
            $structure = isset($data['structure_uuid']) ? SalaryStructure::where('uuid', $data['structure_uuid'])->where('status', 'active')->firstOrFail() : null;
            if ($structure && ($structure->effective_from > $data['effective_from'] || ($structure->effective_to && $structure->effective_to < $data['effective_from']))) $this->invalid('structure_uuid', 'The structure version does not cover the effective date.');
            if ($structure?->currency && $structure->currency !== $group->currency) $this->invalid('structure_uuid', 'Structure currency differs from the group currency.');
            $this->assertCurrencyConsistent($employee, $group, $data['effective_from']);
            $built = $this->calc->build($data['lines'], $group, $data['effective_from'], $data['effective_to'] ?? null, $structure);
            $record = EmployeeCompensation::create([
                'employee_id' => $employee->id, 'payroll_group_id' => $group->id, 'structure_id' => $structure?->id, 'supersedes_id' => $data['supersedes_id'] ?? null,
                'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null, 'reason' => $data['reason'], 'status' => 'pending', 'created_by' => $actor->id,
                'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash, 'currency' => $group->currency, 'pay_frequency' => $group->frequency, 'source' => $data['source'] ?? 'manual',
            ] + $built['summary']);
            foreach ($built['lines'] as $l) CompensationLine::create(['compensation_id' => $record->id, 'component_id' => $l['component']->id, 'amount' => $l['amount'], 'sequence' => $l['sequence'], 'basis' => $l['basis']]);
            if ($notify) $this->notify($actor, $employee, 'compensation:'.$record->uuid, 'Review compensation revision');
            Audit::record('compensation.requested', 'payroll', $record->uuid, ['employee_uuid' => $employee->uuid]);

            return $record;
        });
    }

    /** One payroll currency per employee per effective date: a currency change must be a new group/entity, never a silent mix. */
    private function assertCurrencyConsistent(Employee $employee, PayrollGroup $group, string $from): void
    {
        $prev = EmployeeCompensation::where('employee_id', $employee->id)->where('status', 'approved')->where('effective_from', '<=', $from)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))->first();
        if ($prev && $prev->currency && $prev->currency !== $group->currency && $prev->payroll_group_id !== $group->id && empty($this->allowCurrencyChange)) {
            // A currency change is allowed only through an explicit group transfer; the new record starts a fresh segment in the new currency.
            if (app(\App\Domain\Payroll\Groups\GroupResolver::class)->groupIdFor($employee->id, $from) !== $group->id) $this->invalid('group_uuid', 'The employee is paid in '.$prev->currency.'; moving to '.$group->currency.' requires a payroll group transfer effective on that date.');
        }
    }

    public bool $allowCurrencyChange = false;

    public function notify(User $actor, Employee $employee, string $source, string $title): void
    {
        $approvers = User::where('tenant_id', app(TenantContext::class)->id)->where('active', true)->where('id', '!=', $actor->id)->whereHas('roles.permissions', fn ($q) => $q->where('key', 'payroll.compensation.approve'))->get();
        foreach ($approvers as $approver) {
            if (app(DataScope::class)->canAccess($approver, $employee, 'payroll.compensation.approve')) app(TaskService::class)->createOnce([
                'title' => $title, 'description' => 'Review the effective dates and compensation in the payroll workspace.', 'assignee_id' => $approver->id, 'status' => 'open', 'priority' => 'normal',
                'source_module' => 'payroll', 'source_entity' => $source, 'action' => '/payroll', 'metadata' => ['approval_request_id' => explode(':', $source)[1]],
            ]);
        }
    }

    public function decide(User $actor, string $uuid, string $decision): EmployeeCompensation
    {
        abort_unless($actor->allows('payroll.compensation.approve'), 403);
        $initial = EmployeeCompensation::where('uuid', $uuid)->firstOrFail();
        abort_unless(app(DataScope::class)->canAccess($actor, $initial->employee_id, 'payroll.compensation.approve'), 404);
        return DB::transaction(function () use ($actor, $initial, $decision) {
            Employee::whereKey($initial->employee_id)->lockForUpdate()->firstOrFail();
            $record = EmployeeCompensation::whereKey($initial->id)->lockForUpdate()->firstOrFail();
            abort_if($record->created_by === $actor->id, 403, 'A different authorized person must review this revision.');
            abort_unless($record->status === 'pending', 409, 'This revision has already been reviewed.');
            $this->settle($record, $decision, $actor, false);
            app(TaskService::class)->completeBySource('payroll', 'compensation:'.$record->uuid);
            Audit::record('compensation.'.$decision, 'payroll', $record->uuid);

            return $record;
        });
    }

    /**
     * Applies an approval/rejection. $splice = revision semantics (end the previous segment, supersede same-start segments, bound this one
     * by the next approved segment); otherwise an overlapping approved record is refused.
     */
    public function settle(EmployeeCompensation $record, string $decision, User $actor, bool $splice): void
    {
        if ($decision === 'approved') {
            $approved = EmployeeCompensation::where('employee_id', $record->employee_id)->where('status', 'approved')->where('id', '!=', $record->id)->orderBy('effective_from')->get();
            $from = substr((string) $record->effective_from, 0, 10);
            $to = $record->effective_to ? substr((string) $record->effective_to, 0, 10) : null;
            $overlapping = $approved->filter(fn ($a) => substr((string) $a->effective_from, 0, 10) <= ($to ?? '9999-12-31') && (! $a->effective_to || substr((string) $a->effective_to, 0, 10) >= $from));
            if (! $splice && $overlapping->isNotEmpty()) $this->invalid('effective_from', 'An approved compensation already covers this period. Historical records cannot be silently overwritten.');
            if ($splice) {
                $next = $approved->filter(fn ($a) => substr((string) $a->effective_from, 0, 10) > $from)->first();
                if ($next) { $cap = CarbonImmutable::parse(substr((string) $next->effective_from, 0, 10))->subDay()->toDateString(); if ($to === null || $to > $cap) $record->effective_to = $to = $cap; }
                foreach ($approved as $a) {
                    $af = substr((string) $a->effective_from, 0, 10); $at = $a->effective_to ? substr((string) $a->effective_to, 0, 10) : null;
                    if ($af > ($to ?? '9999-12-31') || ($at !== null && $at < $from)) continue;
                    if ($af >= $from) $a->update(['status' => 'superseded']);
                    else $a->update(['effective_to' => CarbonImmutable::parse($from)->subDay()->toDateString()]);
                }
            }
            app(DomainEvents::class)->record(PayrollEvents::COMPENSATION_ASSIGNED, ['employee' => $record->employee_id, 'summary' => 'Compensation approved', 'effective_date' => $from, 'actor_id' => $actor->id,
                'payload' => ['compensation_uuid' => $record->uuid, 'backdated' => $from < now()->toDateString()]]);
        }
        $record->update(['status' => $decision, 'approved_by' => $actor->id, 'approved_at' => now()]);
    }

    /** Amount visibility is decided by the caller (reveal permission). */
    public function present(EmployeeCompensation $record, bool $reveal = true): array
    {
        $record->loadMissing(['employee.person', 'group', 'lines.component', 'structure']);
        $mask = fn ($v) => $reveal ? $v : null;
        $date = fn ($v) => $v ? substr((string) $v, 0, 10) : null;

        return ['uuid' => $record->uuid, 'employee_uuid' => $record->employee->uuid, 'employee_name' => $record->employee->person->display_name,
            'group_uuid' => $record->group->uuid, 'group_name' => $record->group->name, 'currency' => $record->group->currency,
            'effective_from' => $record->effective_from, 'effective_to' => $record->effective_to, 'status' => $record->status,
            'reason' => $record->reason, 'can_review' => auth()->id() !== $record->created_by && $record->status === 'pending',
            'structure' => $record->structure ? ['uuid' => $record->structure->uuid, 'code' => $record->structure->code, 'version' => $record->structure->version] : null,
            'source' => $record->source, 'pay_frequency' => $record->pay_frequency, 'masked' => ! $reveal,
            'summary' => ['base_pay' => $mask($record->base_pay), 'gross_reference' => $mask($record->gross_reference), 'annual_compensation' => $mask($record->annual_compensation), 'daily_rate' => $mask($record->daily_rate), 'hourly_rate' => $mask($record->hourly_rate)],
            'lines' => $record->lines->sortBy('sequence')->values()->map(fn ($line) => ['component_uuid' => $line->component->uuid, 'code' => $line->component->code, 'version' => $line->component->version, 'kind' => $line->component->kind,
                'amount' => $mask($line->amount), 'deferred' => (bool) ($line->basis['deferred'] ?? false), 'formula' => $line->basis['formula'] ?? null])->all()];
    }

    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field => $message]); }
}
