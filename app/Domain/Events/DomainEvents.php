<?php

namespace App\Domain\Events;

use App\Domain\Access\Classification;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantContext;

/**
 * Records business events and announces them to in-process listeners.
 *
 * Modules never call each other directly for side effects; they record an event and other modules
 * (notifications, My Work, future automation/payroll/leave) subscribe to DomainEventRecorded.
 * Timeline entries come from these rows, so there is no second hand-maintained history.
 */
final class DomainEvents
{
    /**
     * @param  array{employee?:Employee|int|null, person_id?:int|null, subject?:array{0:string,1:int}|null, summary:string, payload?:array, classification?:string, effective_date?:string|null, actor_id?:int|null}  $data
     */
    public function record(string $type, array $data): DomainEvent
    {
        abort_unless(app(TenantContext::class)->id, 422, 'Tenant context is required.');
        $employee = $data['employee'] ?? null;
        $employeeModel = $employee instanceof Employee ? $employee : ($employee ? Employee::query()->find($employee) : null);
        $event = DomainEvent::create([
            'type' => $type,
            'employee_id' => $employeeModel?->id,
            'person_id' => $data['person_id'] ?? $employeeModel?->person_id,
            'subject_type' => $data['subject'][0] ?? null,
            'subject_id' => $data['subject'][1] ?? null,
            'actor_id' => array_key_exists('actor_id', $data) ? $data['actor_id'] : auth()->id(),
            'summary' => mb_substr($data['summary'], 0, 500),
            'payload' => $data['payload'] ?? null,
            'classification' => $data['classification'] ?? Classification::EMPLOYEE,
            'effective_date' => $data['effective_date'] ?? null,
            'occurred_at' => now(),
        ]);
        event(new DomainEventRecorded($event));

        return $event;
    }
}
