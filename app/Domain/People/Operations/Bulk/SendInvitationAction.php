<?php

namespace App\Domain\People\Operations\Bulk;

use App\Domain\People\Lifecycle\InvitationService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Operations\BulkAction;
use App\Domain\People\Operations\DescribesBulkAction;
use App\Models\User;
use Illuminate\Support\Collection;

/** Invites the selected employees to activate their accounts (people.invite). Ineligible employees are skipped in the preview. */
class SendInvitationAction implements BulkAction, DescribesBulkAction
{
    public function key(): string
    {
        return 'send_invitation';
    }

    public function label(): string
    {
        return 'Send invitation';
    }

    public function description(): string
    {
        return 'Invite the selected employees to activate their accounts.';
    }

    public function permission(): string
    {
        return 'people.invite';
    }

    public function validate(array $params): array
    {
        return [];
    }

    public function preview(User $user, Collection $employees, array $params): array
    {
        $service = app(InvitationService::class);
        $out = [];
        foreach ($employees as $employee) {
            $reason = $service->blocker($employee);
            $out[$employee->id] = $reason ? ['status' => 'skipped', 'reason' => $reason] : ['status' => 'ok'];
        }

        return $out;
    }

    public function apply(User $user, Employee $employee, array $params): void
    {
        app(InvitationService::class)->invite($user, $employee);
    }
}
