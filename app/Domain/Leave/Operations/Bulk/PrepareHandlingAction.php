<?php

namespace App\Domain\Leave\Operations\Bulk;

use App\Domain\Leave\Transfers\TransferService;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Prepares (never applies) leave handling previews - transfer, policy change or separation - for many employees at once. */
final class PrepareHandlingAction implements LeaveBulkAction
{
    public function key(): string
    {
        return 'prepare_handling';
    }

    public function label(): string
    {
        return 'Prepare leave handling';
    }

    public function description(): string
    {
        return 'Create reviewable leave handling plans (transfer, policy change, separation) for the selected employees. Nothing is posted until a plan is applied.';
    }

    public function permission(): string
    {
        return 'leave.manage';
    }

    public function validate(array $params): array
    {
        $v = Validator::make($params, ['trigger' => 'required|in:transfer,separation,policy_change', 'effective_date' => 'required|date_format:Y-m-d', 'balance_mode' => 'nullable|in:preserve,transfer,convert,expire']);
        if ($v->fails()) {
            throw new ValidationException($v);
        }

        return $v->validated();
    }

    public function preview(User $user, array $employees, array $params): array
    {
        $out = [];
        foreach ($employees as $e) {
            try {
                $plan = app(TransferService::class)->plan($e->id, $params['trigger'], $params['effective_date'], ['mode' => $params['balance_mode'] ?? null]);
                $out[$e->id] = ['status' => $plan['can_apply'] ? ($plan['warnings'] ? 'warning' : 'ok') : 'error', 'detail' => ['accounts' => count($plan['items']), 'warnings' => array_column($plan['warnings'], 'key')]];
            } catch (\Throwable $ex) {
                $out[$e->id] = ['status' => 'error', 'reason' => $ex instanceof ValidationException ? collect($ex->errors())->flatten()->first() : 'Could not build the plan.'];
            }
        }

        return $out;
    }

    public function run(User $user, object $employee, array $params): array
    {
        $row = app(TransferService::class)->preview($employee->id, $params['trigger'], $params['effective_date'], ['mode' => $params['balance_mode'] ?? null], $user);

        return ['status' => 'ok', 'detail' => ['transfer_uuid' => $row->uuid]];
    }
}
