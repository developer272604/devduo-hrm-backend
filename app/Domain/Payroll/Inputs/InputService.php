<?php

namespace App\Domain\Payroll\Inputs;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Payroll\Models\PayrollInput;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Results\ResultRejected;
use App\Domain\Payroll\Support\Decimal;
use App\Domain\Payroll\Support\PayrollSettings;
use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Manual / API payroll inputs (variable pay, bonus, commission, reimbursement, overtime hours). Approval-gated per settings; maker != checker. */
final class InputService
{
    public const MANUAL_SOURCES = ['variable_pay', 'bonus', 'commission', 'reimbursement', 'overtime', 'adjustment', 'manual', 'api'];

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): PayrollInput
    {
        return DB::transaction(function () use ($actor, $data) {
            $employee = app(DataScope::class)->constrain(Employee::query(), $actor, 'payroll.inputs.manage')->where('uuid', $data['employee_uuid'])->firstOrFail();
            $period = PayrollPeriod::where('uuid', $data['period_uuid'])->firstOrFail();
            $this->assertOpen($period);
            foreach (['amount', 'quantity'] as $f) {
                if (isset($data[$f]) && $data[$f] !== null && ! preg_match('/^-?\d{1,14}(\.\d{1,6})?$/', (string) $data[$f])) {
                    throw ValidationException::withMessages([$f => 'Enter a plain decimal number (no thousands separators).']);
                }
            }
            if (($data['amount'] ?? null) === null && ($data['quantity'] ?? null) === null) {
                throw ValidationException::withMessages(['amount' => 'Provide an amount or a quantity.']);
            }
            $key = substr(hash('sha256', implode('|', [$data['source'], $data['kind'], $employee->id, $period->id, $data['component_code'] ?? '', $data['amount'] ?? '', $data['quantity'] ?? '', $data['source_ref'] ?? ''])), 0, 40);
            if ($existing = PayrollInput::where('dedupe_key', $key)->first()) {
                abort_unless(in_array($existing->status, ['rejected', 'void'], true) === false, 409, 'This input was already entered and then rejected or voided.');

                return $existing; // duplicate submissions are idempotent
            }
            $needsApproval = in_array($data['source'], PayrollSettings::get('inputs.require_approval_for', []), true);
            $input = PayrollInput::create(['employee_id' => $employee->id, 'payroll_group_id' => $period->payroll_group_id, 'period_id' => $period->id, 'source' => $data['source'], 'kind' => $data['kind'],
                'component_code' => $data['component_code'] ?? null, 'quantity' => $data['quantity'] ?? null, 'amount' => $data['amount'] ?? null, 'currency' => $data['currency'] ?? ($period->group->currency ?? null),
                'status' => $needsApproval ? 'pending' : 'approved', 'is_final' => true, 'source_ref' => $data['source_ref'] ?? 'manual:'.$actor->id, 'dedupe_key' => $key, 'meta' => $data['meta'] ?? null,
                'import_id' => $data['import_id'] ?? null, 'created_by' => $actor->id, 'approved_by' => $needsApproval ? null : $actor->id, 'approved_at' => $needsApproval ? null : now()]);
            Audit::record('payroll.input.created', 'payroll', $input->uuid, ['source' => $input->source, 'status' => $input->status]);

            return $input;
        });
    }

    public function decide(User $actor, string $uuid, string $decision, ?string $reason = null): PayrollInput
    {
        return DB::transaction(function () use ($actor, $uuid, $decision, $reason) {
            $input = PayrollInput::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless(app(DataScope::class)->canAccess($actor, $input->employee_id, 'payroll.inputs.approve'), 404);
            abort_unless($input->status === 'pending', 409, 'This input has already been reviewed.');
            abort_if($input->created_by === $actor->id, 403, 'A different authorized person must review this input.');
            $input->update($decision === 'approved' ? ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()] : ['status' => 'rejected', 'rejection_reason' => mb_substr((string) $reason, 0, 400)]);
            Audit::record('payroll.input.'.$decision, 'payroll', $input->uuid);

            return $input;
        });
    }

    public function void(User $actor, string $uuid): PayrollInput
    {
        return DB::transaction(function () use ($actor, $uuid) {
            $input = PayrollInput::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless(app(DataScope::class)->canAccess($actor, $input->employee_id, 'payroll.inputs.manage'), 404);
            abort_if($input->status === 'consumed', 423, 'This input was used by a locked payroll run. Reopen the run to change it.');
            $input->update(['status' => 'void']);
            Audit::record('payroll.input.voided', 'payroll', $input->uuid);

            return $input;
        });
    }

    /** Inputs cannot change for a period whose run is approved or locked (reopen first). */
    private function assertOpen(PayrollPeriod $period): void
    {
        $locked = PayrollRun::where('period_id', $period->id)->whereIn('status', ['approved', 'locked', 'outputs', 'payment', 'closed'])->where('run_type', 'regular')->first();
        if ($locked) {
            throw ResultRejected::locked($locked->status);
        }
    }

    /** @return array<string,mixed> */
    public function present(PayrollInput $i): array
    {
        $i->loadMissing('employee.person');

        return ['uuid' => $i->uuid, 'employee_uuid' => $i->employee->uuid, 'employee_name' => $i->employee->person->display_name, 'source' => $i->source, 'kind' => $i->kind, 'component_code' => $i->component_code,
            'quantity' => $i->quantity === null ? null : Decimal::str($i->quantity, 6), 'amount' => $i->amount === null ? null : Decimal::str($i->amount, 4), 'currency' => $i->currency, 'status' => $i->status,
            'is_final' => (bool) $i->is_final, 'source_ref' => $i->source_ref, 'rejection_reason' => $i->rejection_reason, 'created_at' => $i->created_at];
    }
}
