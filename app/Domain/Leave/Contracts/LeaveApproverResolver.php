<?php

namespace App\Domain\Leave\Contracts;

use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * REUSE NOTE: there is no leave approval engine. Implementations MUST delegate routing to
 * App\Domain\Attendance\Requests\ApproverResolver (manager -> hr fallback, delegation, self-approval exclusion), extending
 * its KINDS/permission map with 'leave' => 'leave.approve' (workstream C owns that one-line edit), and create the approver's
 * task through App\Domain\Work\TaskService (My Work). Decisions are recorded in leave_request_actions.
 */
interface LeaveApproverResolver
{
    public function totalSteps(Employee $employee, string $date): int;

    public function forEmployee(Employee $employee, string $date, int $step = 1, array $exclude = []): ?User;

    /** @return Collection<int,User> */
    public function candidates(Employee $employee, string $date, int $step = 1, array $exclude = []): Collection;
}
