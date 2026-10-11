<?php

namespace App\Domain\Payroll\Runs;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Contracts\PackResolver;
use App\Domain\Payroll\Locks\PayrollLockService;
use App\Domain\Payroll\Models\PayrollAdjustment;
use App\Domain\Payroll\Models\PayrollGroup;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Models\PayrollRunEmployee;
use App\Domain\Payroll\Models\PayrollValidationIssue;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\Payroll\Support\PayrollStates;
use App\Domain\People\Models\Employee;
use App\Models\User;
use App\Support\RequestFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Run lifecycle: create (idempotent), roster, exclusions / holds, cancel, findings, adjustments (arrears / recoveries with maker-checker). */
final class RunService
{
    public const RUN_TYPES = ['regular', 'off_cycle', 'correction'];

    public function __construct(private RosterBuilder $roster) {}

    /** @return array<string,mixed> */
    public function preview(User $actor, array $data): array
    {
        [$group, $period] = $this->target($data);
        $built = $this->roster->build($group, $period, $actor, $data['employee_uuids'] ?? null);

        return ['period' => $period->uuid, 'group' => $group->uuid, 'employees' => count($built['rows']), 'out_of_scope' => $built['out_of_scope'], 'skipped' => $built['skipped'],
            'joiners' => count(array_filter($built['rows'], fn ($r) => $r['snapshot']['joiner'])), 'leavers' => count(array_filter($built['rows'], fn ($r) => $r['snapshot']['leaver'])),
            'transfers' => count(array_filter($built['rows'], fn ($r) => $r['snapshot']['transfer_in'] || $r['snapshot']['transfer_out'])), 'currency' => $group->currency];
    }

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data, string $idempotencyKey): PayrollRun
    {
        $hash = RequestFingerprint::make($data);
        if ($existing = PayrollRun::where('idempotency_key', $idempotencyKey)->first()) {
            abort_unless($existing->created_by === $actor->id && hash_equals((string) $existing->request_hash, $hash), 409, 'This idempotency key belongs to a different request.');

            return $existing;
        }
        $type = $data['run_type'] ?? 'regular';
        $parent = null;
        if ($type === 'correction') {
            $parent = PayrollRun::where('uuid', $data['parent_run_uuid'] ?? '')->whereIn('status', ['locked', 'outputs', 'payment', 'closed'])->first();
            if (! $parent || empty($data['employee_uuids'])) {
                throw ValidationException::withMessages(['parent_run_uuid' => 'A correction run needs a locked parent run and the employees to correct.']);
            }
            $period = $parent->period;
            $group = $parent->group;
        } else {
            [$group, $period] = $this->target($data);
        }
        if ($type !== 'regular' && empty($data['employee_uuids'])) {
            throw ValidationException::withMessages(['employee_uuids' => 'Name the employees for an off-cycle or correction run.']);
        }

        return DB::transaction(function () use ($actor, $data, $idempotencyKey, $hash, $group, $period, $type, $parent) {
            $group = PayrollGroup::whereKey($group->id)->lockForUpdate()->firstOrFail();
            if ($type === 'regular') {
                if (PayrollRun::where('period_id', $period->id)->where('run_type', 'regular')->where('status', '!=', 'cancelled')->exists()) {
                    abort(409, 'This period already has an active regular run.');
                }
                if (! in_array($period->state, ['input_open', 'processing', 'validation', 'reopened'], true)) {
                    throw ValidationException::withMessages(['period_uuid' => 'The period is "'.$period->state.'" and cannot start a new regular run.']);
                }
            }
            $built = $this->roster->build($group, $period, $actor, $data['employee_uuids'] ?? null);
            if (! $built['rows']) {
                throw ValidationException::withMessages(['employee_uuids' => 'No employee qualifies for this run.']);
            }
            $pack = app()->bound(PackResolver::class) ? app(PackResolver::class)->forGroup($group->id, substr((string) $period->period_end, 0, 10)) : null;
            $run = PayrollRun::create(['payroll_group_id' => $group->id, 'period_id' => $period->id, 'run_number' => (int) PayrollRun::where('period_id', $period->id)->max('run_number') + 1, 'run_type' => $type,
                'off_cycle_reason' => $data['off_cycle_reason'] ?? ($type === 'correction' ? 'correction' : null), 'status' => 'draft', 'stage' => 1, 'currency' => $group->currency, 'country_pack_version_id' => $pack?->id,
                'parent_run_id' => $parent?->id, 'settings_snapshot' => PayrollSettings::all(), 'employees_total' => count($built['rows']), 'idempotency_key' => $idempotencyKey, 'request_hash' => $hash,
                'created_by' => $actor->id, 'notes' => $data['notes'] ?? null]);
            foreach ($built['rows'] as $r) {
                PayrollRunEmployee::create(['run_id' => $run->id, 'employee_id' => $r['employee_id'], 'payroll_group_id' => $group->id, 'employment_snapshot' => $r['snapshot']]);
            }
            $readiness = ['out_of_scope' => $built['out_of_scope'], 'skipped' => $built['skipped'], 'statutory_pack' => $pack !== null];
            $run->settings_snapshot = ($run->settings_snapshot ?? []) + ['readiness' => $readiness];
            foreach (['readiness', 'inputs'] as $next) {
                PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, $run->status, $next, 'status');
                $run->status = $next;
            }
            $run->stage = 2;
            $run->save();
            Audit::record('payroll.run.created', 'payroll', $run->uuid, ['type' => $type, 'employees' => count($built['rows'])]);

            return $run;
        });
    }

    /** Exclude / hold / include an employee. Allowed only while the run can still be calculated. */
    public function setEmployeeStatus(User $actor, PayrollRun $run, string $employeeUuid, string $action, ?string $reason): PayrollRunEmployee
    {
        return DB::transaction(function () use ($actor, $run, $employeeUuid, $action, $reason) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($run->status, PayrollStates::CALCULABLE_RUN, true), 423, 'This run can no longer be changed.');
            $employee = Employee::where('uuid', $employeeUuid)->firstOrFail();
            abort_unless(app(DataScope::class)->canAccess($actor, $employee, 'payroll.run'), 404);
            $re = PayrollRunEmployee::where('run_id', $run->id)->where('employee_id', $employee->id)->firstOrFail();
            if (in_array($action, ['excluded', 'held'], true) && strlen(trim((string) $reason)) < 3) {
                throw ValidationException::withMessages(['reason' => 'Give a short reason.']);
            }
            $re->update($action === 'include' ? ['status' => 'pending', 'exclusion_reason' => null, 'calc_pass' => 0] : ['status' => $action, 'exclusion_reason' => mb_substr((string) $reason, 0, 160)]);
            PayrollLockService::logChange($run, 'employee_'.$action, ['employee_uuid' => $employeeUuid]);
            Audit::record('payroll.run.employee_'.$action, 'payroll', $run->uuid, ['employee_uuid' => $employeeUuid]);

            return $re;
        });
    }

    public function cancel(User $actor, PayrollRun $run, string $reason): PayrollRun
    {
        return DB::transaction(function () use ($run, $reason) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            PayrollStates::assertTransition(PayrollStates::RUN_TRANSITIONS, $run->status, 'cancelled', 'status');
            $run->update(['status' => 'cancelled', 'notes' => trim($run->notes."\nCancelled: ".$reason)]);
            Audit::record('payroll.run.cancelled', 'payroll', $run->uuid, ['reason' => $reason]);

            return $run;
        });
    }

    public function waive(User $actor, string $issueUuid, string $note): PayrollValidationIssue
    {
        $issue = PayrollValidationIssue::where('uuid', $issueUuid)->firstOrFail();
        $run = PayrollRun::findOrFail($issue->run_id);
        abort_unless(in_array($run->status, ['review', 'reopened', 'calculating', 'inputs'], true), 423, 'Findings can only be waived before approval.');
        abort_if($issue->severity === 'critical', 422, 'Critical findings cannot be waived; fix the cause and recalculate.');
        $issue->update(['status' => 'waived', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_note' => mb_substr($note, 0, 400)]);
        Audit::record('payroll.validation.waived', 'payroll', $issue->uuid);

        return $issue;
    }

    /** Arrears / recoveries / manual-net adjustments with maker-checker (signed amount: positive pays, negative recovers). */
    public function createAdjustment(User $actor, array $data, string $idempotencyKey): PayrollAdjustment
    {
        return DB::transaction(function () use ($actor, $data, $idempotencyKey) {
            if ($existing = PayrollAdjustment::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }
            $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.adjust')->where('uuid', $data['employee_uuid'])->firstOrFail();
            $target = PayrollPeriod::where('uuid', $data['settlement_period_uuid'])->firstOrFail();
            $origin = isset($data['origin_period_uuid']) ? PayrollPeriod::where('uuid', $data['origin_period_uuid'])->firstOrFail() : null;
            abort_if(in_array($target->state, ['locked', 'paid', 'closed'], true), 423, 'The settlement period is locked.');
            $adj = PayrollAdjustment::create(['employee_id' => $employee->id, 'kind' => $data['kind'], 'status' => 'pending', 'origin_period_id' => $origin?->id, 'settlement_period_id' => $target->id,
                'component_code' => $data['component_code'] ?? null, 'amount' => $data['amount'], 'currency' => $target->group->currency, 'reason' => $data['reason'], 'trace' => $data['trace'] ?? null,
                'created_by' => $actor->id, 'idempotency_key' => $idempotencyKey]);
            Audit::record('payroll.adjustment.created', 'payroll', $adj->uuid, ['kind' => $adj->kind]);

            return $adj;
        });
    }

    public function decideAdjustment(User $actor, string $uuid, string $decision): PayrollAdjustment
    {
        return DB::transaction(function () use ($actor, $uuid, $decision) {
            $adj = PayrollAdjustment::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless(app(DataScope::class)->canAccess($actor, $adj->employee_id, 'payroll.adjust.approve'), 404);
            abort_unless($adj->status === 'pending', 409, 'This adjustment has already been reviewed.');
            abort_if($adj->created_by === $actor->id, 403, 'A different authorized person must review this adjustment.');
            if ($adj->kind === 'reversal') {
                $original = PayrollAdjustment::whereKey($adj->reverses_id)->lockForUpdate()->firstOrFail();
                abort_unless(in_array($original->status, ['approved', 'applied'], true), 409, 'Only an approved or applied adjustment can be reversed.');
                if ($decision === 'approved') {
                    $original->update(['status' => 'reversed']);
                }
            }
            $adj->update(['status' => $decision, 'approved_by' => $actor->id, 'approved_at' => now()]);
            Audit::record('payroll.adjustment.'.$decision, 'payroll', $adj->uuid);

            return $adj;
        });
    }

    public function reverseAdjustment(User $actor, string $uuid, string $settlementPeriodUuid, string $reason, string $idempotencyKey): PayrollAdjustment
    {
        return DB::transaction(function () use ($actor, $uuid, $settlementPeriodUuid, $reason, $idempotencyKey) {
            abort_unless($actor->allows('payroll.adjust'), 403);
            if ($existing = PayrollAdjustment::where('idempotency_key', $idempotencyKey)->first()) {
                return $existing;
            }
            $original = PayrollAdjustment::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless(app(DataScope::class)->canAccess($actor, $original->employee_id, 'payroll.adjust'), 404);
            abort_unless(in_array($original->status, ['approved', 'applied'], true), 409, 'Only an approved or applied adjustment can be reversed.');
            abort_if($original->kind === 'reversal', 409, 'A reversal cannot itself be reversed.');
            abort_if(PayrollAdjustment::where('reverses_id', $original->id)->whereIn('status', ['pending', 'approved', 'applied'])->exists(), 409, 'An active reversal already exists.');
            $target = PayrollPeriod::where('uuid', $settlementPeriodUuid)->firstOrFail();
            abort_if(in_array($target->state, ['locked', 'paid', 'closed'], true), 423, 'The settlement period is locked.');
            $amount = Decimal::str(Decimal::sub('0', (string) $original->amount), 4);
            $reversal = PayrollAdjustment::create(['employee_id' => $original->employee_id, 'kind' => 'reversal', 'status' => 'pending', 'origin_period_id' => $original->origin_period_id,
                'settlement_period_id' => $target->id, 'component_code' => $original->component_code, 'amount' => $amount, 'currency' => $original->currency,
                'reason' => $reason, 'trace' => ['reverses_uuid' => $original->uuid, 'reverses_kind' => $original->kind], 'reverses_id' => $original->id, 'created_by' => $actor->id, 'idempotency_key' => $idempotencyKey]);
            Audit::record('payroll.adjustment.reversal_requested', 'payroll', $reversal->uuid, ['reverses_uuid' => $original->uuid]);

            return $reversal;
        });
    }

    /** @return array{0:PayrollGroup,1:PayrollPeriod} */
    private function target(array $data): array
    {
        $period = PayrollPeriod::where('uuid', $data['period_uuid'] ?? '')->firstOrFail();
        $group = PayrollGroup::whereKey($period->payroll_group_id)->where('active', true)->firstOrFail();

        return [$group, $period];
    }
}
