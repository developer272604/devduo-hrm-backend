<?php

namespace App\Domain\Leave\Operations\Bulk;

use App\Models\User;

/**
 * Registry of leave bulk actions. Any leave workstream (or a later phase) registers an action with register() from its own Boot;
 * the Operations endpoints list, preview and run whatever is registered, so adding an action never touches routes or controllers.
 *
 * Contract: preview() never writes; run() is idempotent per employee where the action allows it and returns one result per employee.
 * The engine (LeaveBulkRunner) enforces: permission of the action, tenant + data scope of every selected employee, a hard cap of
 * LeaveBulkActions::MAX employees per call, and one audit record per run.
 */
final class LeaveBulkActions
{
    public const MAX = 500;

    /** @var array<string,LeaveBulkAction|string> */
    private array $actions = [];

    public function register(LeaveBulkAction|string $action): void
    {
        $instance = is_string($action) ? app($action) : $action;
        $this->actions[$instance->key()] = $instance;
    }

    public function find(string $key): ?LeaveBulkAction
    {
        return $this->actions[$key] ?? null;
    }

    /** @return array<int,array<string,mixed>> actions the user may run */
    public function catalogue(User $user): array
    {
        $out = [];
        foreach ($this->actions as $a) {
            if ($user->allows($a->permission())) {
                $out[] = ['key' => $a->key(), 'label' => $a->label(), 'description' => $a->description(), 'permission' => $a->permission(), 'max_employees' => self::MAX];
            }
        }

        return $out;
    }
}
