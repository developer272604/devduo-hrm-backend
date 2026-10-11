<?php

namespace App\Domain\Leave\Support;

/**
 * Domain event types recorded through App\Domain\Events\DomainEvents::record() (never call other modules directly).
 * Payloads must stay free of medical reasons/documents; use request uuid references only.
 */
final class LeaveEvents
{
    public const REQUEST_SUBMITTED = 'leave.request.submitted';

    public const REQUEST_APPROVED = 'leave.request.approved';

    public const REQUEST_REJECTED = 'leave.request.rejected';

    public const REQUEST_CANCELLED = 'leave.request.cancelled';

    public const REQUEST_WITHDRAWN = 'leave.request.withdrawn';

    /** Attendance listens: recompute the days in payload.dates for payload.employee_id. */
    public const COVERAGE_CHANGED = 'leave.coverage.changed';

    public const BALANCE_ADJUSTED = 'leave.balance.adjusted';

    public const ACCRUAL_COMPLETED = 'leave.accrual.completed';

    public const CARRY_FORWARD_COMPLETED = 'leave.carry_forward.completed';

    public const BALANCE_EXPIRING = 'leave.balance.expiring';

    public const COMP_OFF_GRANTED = 'leave.comp_off.granted';

    public const LOCK_CHANGED = 'leave.lock.changed';
}
