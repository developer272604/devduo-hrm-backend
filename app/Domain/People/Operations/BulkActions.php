<?php

namespace App\Domain\People\Operations;

use App\Domain\People\Operations\Bulk\ActivateAction;
use App\Domain\People\Operations\Bulk\AssignmentAction;
use App\Domain\People\Operations\Bulk\AssignManagerAction;
use App\Domain\People\Operations\Bulk\DeactivateAction;
use App\Domain\People\Operations\Bulk\ExportAction;
use App\Domain\People\Operations\Bulk\TagAction;
use App\Models\User;

/** Registry of bulk actions. Other workstreams add theirs: app(BulkActions::class)->register(new SendInvitationAction). */
final class BulkActions
{
    /** @var array<string,BulkAction> */
    private array $actions = [];

    public function registerDefaults(): static
    {
        foreach ([new AssignmentAction('assign_department', 'department_id', 'Department'), new AssignmentAction('assign_location', 'location_id', 'Location'),
            new AssignmentAction('assign_employee_type', 'employee_type_id', 'Employee type'), new AssignManagerAction, new TagAction(true), new TagAction(false),
            new ActivateAction, new DeactivateAction, new ExportAction] as $action) {
            $this->register($action);
        }

        return $this;
    }

    public function register(BulkAction|string $action): void
    {
        $action = is_string($action) ? app($action) : $action;
        $this->actions[$action->key()] = $action;
    }

    public function has(string $key): bool
    {
        return isset($this->actions[$key]);
    }

    public function get(string $key): BulkAction
    {
        return $this->actions[$key] ?? abort(422, 'Unknown bulk action.');
    }

    /** @return array<string,BulkAction> */
    public function all(): array
    {
        return $this->actions;
    }

    /** Actions the viewer may run: people.bulk plus the action's own permission. */
    public function forUser(User $user): array
    {
        return $user->allows('people.bulk') ? array_filter($this->actions, fn (BulkAction $a) => $user->allows($a->permission())) : [];
    }
}
