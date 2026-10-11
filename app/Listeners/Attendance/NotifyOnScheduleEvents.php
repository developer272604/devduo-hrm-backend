<?php

namespace App\Listeners\Attendance;

use App\Domain\Attendance\Requests\RequestSupport;
use App\Domain\Events\DomainEventRecorded;
use Illuminate\Support\Facades\DB;

/**
 * Turns RosterPublished / AttendanceLocked domain events (recorded by other areas) into one deduplicated in-app notice to the affected employee.
 * Auto-discovered by Laravel (handle(DomainEventRecorded)); unrelated events return immediately.
 */
class NotifyOnScheduleEvents
{
    public function __construct(private RequestSupport $support) {}

    public function handle(DomainEventRecorded $e): void
    {
        $event = $e->event;
        if (! in_array($event->type, ['RosterPublished', 'AttendanceLocked'], true) || ! $event->employee_id) {
            return;
        }
        $userId = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->where('employees.id', $event->employee_id)->value('people.user_id');
        $title = $event->type === 'RosterPublished' ? 'Roster published' : 'Attendance locked';
        $this->support->notify($userId ? (int) $userId : null, $title, $event->summary, 'event:'.$event->uuid, '/attendance');
    }
}
