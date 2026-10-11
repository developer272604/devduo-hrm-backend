<?php

namespace App\Domain\Leave\Transfers;

use App\Domain\Events\DomainEventRecorded;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Listens to People domain events and prepares (never applies) leave handling for HR to review:
 *   EmployeeTransferred (country / entity / location / employee type changed) -> trigger "transfer" preview
 *   EmployeeSeparated                                                          -> trigger "separation" preview (final accrual, encashment, negative balance, future leave)
 *   EmployeeRehired                                                            -> trigger "rehire" preview (restoration from the previous employment)
 * Failures are logged and swallowed: a leave problem must never break an HR transaction.
 */
final class PeopleEventListener
{
    /** Assignment fields that can change which leave policy applies. */
    public const POLICY_FIELDS = ['legal_entity_id', 'location_id', 'employee_type_id', 'grade_id', 'country'];

    public function __construct(private TransferService $transfers, private NotificationService $notifications) {}

    public function handle(DomainEventRecorded $recorded): void
    {
        $event = $recorded->event;
        if (! in_array($event->type, ['EmployeeTransferred', 'EmployeeSeparated', 'EmployeeRehired'], true) || ! $event->employee_id) {
            return;
        }
        try {
            if (! in_array('leave', Tenant::query()->whereKey($event->tenant_id)->value('settings')['modules'] ?? [], true)) {
                return;
            }
            $employee = Employee::query()->find($event->employee_id);
            if (! $employee) {
                return;
            }
            $date = substr((string) ($event->effective_date ?? LeaveOps::today()), 0, 10);
            switch ($event->type) {
                case 'EmployeeTransferred':
                    if (! array_intersect(self::POLICY_FIELDS, $event->payload['fields'] ?? [])) {
                        return;
                    }
                    $row = $this->transfers->preview($employee->id, 'transfer', $date, [], null);
                    break;
                case 'EmployeeSeparated':
                    $row = $this->transfers->preview($employee->id, 'separation', $date, [], null);
                    break;
                default:
                    $previous = Employee::query()->where('uuid', $event->payload['previous_employee'] ?? '')->value('id');
                    if (! $previous) {
                        return;
                    }
                    $row = $this->transfers->preview($employee->id, 'rehire', $date, ['previous_employee_id' => (int) $previous], null);
            }
            $this->notify($event->tenant_id, $row->trigger, $row->uuid);
        } catch (\Throwable $e) {
            Log::warning('leave.people_event_failed', ['event' => $event->type, 'error' => $e->getMessage()]);
        }
    }

    private function notify(int $tenantId, string $trigger, string $uuid): void
    {
        $ids = DB::table('users')->join('role_user', 'role_user.user_id', '=', 'users.id')->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('users.tenant_id', $tenantId)->where('users.active', true)->where('permissions.key', 'leave.manage')->distinct()->pluck('users.id')->all();
        foreach ($ids as $id) {
            try {
                $this->notifications->send($id, 'Leave review needed', 'A '.$trigger.' needs its leave balances reviewed.', 'leave', '/leave/operations/transfers/'.$uuid);
            } catch (\Throwable) {
            }
        }
    }
}
