<?php

namespace App\Domain\Payroll\SelfService;

use App\Domain\Access\DataScope;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\User;

/** Shared helpers of workstream C: own-employee resolution (never from request input) and maker-checker task fan-out. */
final class Own
{
    public static function tid(): int
    {
        return (int) app(TenantContext::class)->id;
    }

    public static function employee(User $user): Employee
    {
        $ids = app(DataScope::class)->ownEmployeeIds($user);
        $employee = $ids ? Employee::query()->whereIn('id', $ids)->orderByDesc('id')->first() : null;
        abort_unless($employee, 404, 'No employment record is linked to your account.');

        return $employee;
    }

    /** Creates My Work tasks for every other user holding $permission within data scope. No amounts in text. */
    public static function requestApproval(User $maker, string $permission, ?Employee $subject, string $title, string $entity, string $action): void
    {
        $approvers = User::where('tenant_id', self::tid())->where('active', true)->where('id', '!=', $maker->id)
            ->whereHas('roles.permissions', fn ($q) => $q->where('key', $permission))->get();
        foreach ($approvers as $a) {
            if ($subject && ! app(DataScope::class)->canAccess($a, $subject, $permission)) {
                continue;
            }
            app(TaskService::class)->createOnce(['title' => $title, 'description' => 'Review in the payroll workspace.', 'assignee_id' => $a->id, 'status' => 'open', 'priority' => 'normal',
                'source_module' => 'payroll', 'source_entity' => $entity, 'action' => $action, 'metadata' => ['approval_request_id' => $entity]]);
        }
    }

    public static function mask(?string $value, int $keep = 4): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return str_repeat('*', max(0, mb_strlen($value) - $keep)).mb_substr($value, -$keep);
    }
}
