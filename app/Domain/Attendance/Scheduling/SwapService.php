<?php

namespace App\Domain\Attendance\Scheduling;

use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\ShiftSwapRequest;
use App\Domain\Attendance\Support\AttendanceSettings;
use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Notifications\NotificationService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\ReportingService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Work\TaskService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Controlled shift swaps: swap with a colleague (counterpart must consent), change shift, request an alternate shift.
 * Settings under settings.attendance.swap: enabled, min_notice_hours, max_per_month, same_team_only, same_department_only, same_location_only.
 * Routing: approver_user_id is resolved at submission AND recomputed on decision; active approval_delegations let a delegate decide.
 */
final class SwapService
{
    public const DEFAULTS = ['enabled' => true, 'min_notice_hours' => 24, 'max_per_month' => 4, 'same_team_only' => false, 'same_department_only' => true, 'same_location_only' => false];

    public function __construct(private DatabaseScheduleResolver $resolver, private ConflictDetector $conflicts, private RosterService $rosters, private DomainEvents $events) {}

    public static function settings(): array
    {
        return array_merge(self::DEFAULTS, (array) AttendanceSettings::get('swap', []));
    }

    public function submit(Employee $requester, array $in, User $actor): ShiftSwapRequest
    {
        $cfg = self::settings();
        if (! $cfg['enabled']) {
            Fail::business('swaps_disabled', 'Shift swaps are not enabled for this workspace.', 422);
        }
        $type = $in['type'] ?? null;
        if (! in_array($type, ['swap', 'change', 'alternate'], true)) {
            Fail::field('type', 'Request type must be swap, change or alternate.');
        }
        $date = $in['work_date'] ?? null;
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            Fail::field('work_date', 'Enter the date of the shift (YYYY-MM-DD).');
        }
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($reason === '') {
            Fail::field('reason', 'Tell your manager why you need this change.');
        }
        $mine = $this->resolver->resolve($requester->id, $date);
        if ($type !== 'alternate' && $mine->kind !== 'working') {
            Fail::field('work_date', 'You have no shift on that date to swap or change.');
        }
        $counterpart = null;
        $counterpartDate = null;
        $toShift = null;
        if ($type === 'swap') {
            $counterpart = ! empty($in['counterpart_uuid']) ? Employee::query()->where('uuid', $in['counterpart_uuid'])->first() : null;
            if (! $counterpart || $counterpart->id === $requester->id) {
                Fail::field('counterpart_uuid', 'Choose a colleague to swap with.');
            }
            $counterpartDate = $in['counterpart_date'] ?? $date;
            $parsedCounterpartDate = is_string($counterpartDate) ? CarbonImmutable::createFromFormat('!Y-m-d', $counterpartDate) : false;
            if (! $parsedCounterpartDate || $parsedCounterpartDate->format('Y-m-d') !== $counterpartDate) {
                Fail::field('counterpart_date', 'Enter the colleague shift date (YYYY-MM-DD).');
            }
            $theirs = $this->resolver->resolve($counterpart->id, $counterpartDate);
            if ($theirs->kind !== 'working') {
                Fail::field('counterpart_date', 'That colleague has no shift on that date.');
            }
            $this->assertEligible($requester, $counterpart, $date, $counterpartDate, $cfg);
            $this->assertRestrictions($counterpart, $theirs, $counterpartDate, $cfg, false);
            $toShift = Shift::query()->find($theirs->shiftId);
        } else {
            $toShift = ! empty($in['to_shift_uuid']) ? Shift::query()->where('uuid', $in['to_shift_uuid'])->first() : null;
            if (! $toShift || ! $toShift->active) {
                Fail::field('to_shift_uuid', 'Choose the shift you would like.');
            }
            if ($mine->shiftId === $toShift->id) {
                Fail::field('to_shift_uuid', 'You are already on that shift.');
            }
        }
        $this->assertRestrictions($requester, $mine, $date, $cfg);
        $violations = $this->violations($requester->id, $counterpart?->id, $date, $counterpartDate ?? $date, $mine->shiftId, $toShift?->id, $type);
        if ($violations) {
            Fail::business('swap_violates_rules', 'This request would break a scheduling rule: '.$violations[0]['message'], 422, ['conflicts' => $violations]);
        }
        $approver = $this->approverFor($requester, $date);

        return DB::transaction(function () use ($requester, $counterpart, $counterpartDate, $toShift, $type, $date, $reason, $mine, $approver, $actor) {
            $swap = ShiftSwapRequest::create(['requester_id' => $requester->id, 'counterpart_id' => $counterpart?->id, 'type' => $type, 'work_date' => $date, 'counterpart_date' => $counterpartDate,
                'from_shift_id' => $mine->shiftId, 'to_shift_id' => $toShift?->id, 'reason' => $reason, 'status' => $counterpart ? 'pending' : 'pending',
                'validation' => ['checked_at' => now()->toIso8601String(), 'rules' => 'notice, monthly limit, eligibility, locks, conflicts'], 'approver_user_id' => $approver?->id]);
            Audit::record('requested', 'attendance', 'shift_swap:'.$swap->uuid, ['type' => $type, 'date' => $date, 'counterpart_date' => $counterpartDate]);
            $this->events->record('ShiftSwapRequested', ['employee' => $requester, 'summary' => "Shift {$type} requested for {$date}", 'subject' => ['shift_swap', $swap->id], 'effective_date' => $date, 'payload' => ['swap' => $swap->uuid]]);
            if ($counterpart) {
                $this->tell($counterpart, 'A colleague asked to swap shifts', "{$requester->person->display_name} asked to swap your {$counterpartDate} shift for their {$date} shift. Accept or decline in your schedule.", '/attendance?tab=schedule', $swap, 'consent');
            } else {
                $this->routeToApprover($swap, $requester, $approver);
            }

            return $swap;
        });
    }

    public function respond(ShiftSwapRequest $swap, bool $accept, User $user, ?string $comment): ShiftSwapRequest
    {
        return DB::transaction(function () use ($swap, $accept, $user, $comment) {
            $swap = ShiftSwapRequest::query()->whereKey($swap->id)->lockForUpdate()->firstOrFail();
            $counterpart = $swap->counterpart_id ? Employee::query()->find($swap->counterpart_id) : null;
            abort_unless($counterpart && app(\App\Domain\Access\DataScope::class)->isSelf($user, $counterpart), 404);
            if ($swap->status !== 'pending') {
                Fail::business('swap_not_pending', 'This request has already been answered.', 409);
            }
            $requester = Employee::query()->findOrFail($swap->requester_id);
            app(TaskService::class)->completeBySource('attendance', 'shift_swap:'.$swap->uuid.':consent');
            if (! $accept) {
                $swap->update(['status' => 'rejected', 'decided_at' => now(), 'decision_comment' => $comment ?: 'Declined by colleague']);
                $this->tell($requester, 'Swap declined', "{$counterpart->person->display_name} declined your swap request for {$swap->work_date}.", '/attendance?tab=schedule', $swap, null);
                Audit::record('declined', 'attendance', 'shift_swap:'.$swap->uuid);

                return $swap;
            }
            $swap->update(['status' => 'counterpart_accepted']);
            Audit::record('counterpart_accepted', 'attendance', 'shift_swap:'.$swap->uuid);
            $approver = $this->approverFor($requester, $swap->work_date);
            $swap->update(['approver_user_id' => $approver?->id]);
            $this->routeToApprover($swap, $requester, $approver);

            return $swap;
        });
    }

    public function withdraw(ShiftSwapRequest $swap, User $user): ShiftSwapRequest
    {
        $requester = Employee::query()->findOrFail($swap->requester_id);
        abort_unless(app(\App\Domain\Access\DataScope::class)->isSelf($user, $requester), 404);
        if (! in_array($swap->status, ['pending', 'counterpart_accepted'], true)) {
            Fail::business('swap_closed', 'This request can no longer be withdrawn.', 409);
        }
        $swap->update(['status' => 'withdrawn', 'decided_at' => now()]);
        $this->closeTasks($swap);
        Audit::record('withdrawn', 'attendance', 'shift_swap:'.$swap->uuid);

        return $swap;
    }

    public function decide(ShiftSwapRequest $swap, bool $approve, User $user, ?string $comment): ShiftSwapRequest
    {
        return DB::transaction(function () use ($swap, $approve, $user, $comment) {
            $swap = ShiftSwapRequest::query()->whereKey($swap->id)->lockForUpdate()->firstOrFail();
            $requester = Employee::query()->findOrFail($swap->requester_id);
            if ($swap->status === 'pending' && $swap->type === 'swap') {
                Fail::business('awaiting_counterpart', 'The colleague must accept the swap before a manager can decide.', 409);
            }
            if (! in_array($swap->status, ['pending', 'counterpart_accepted'], true)) {
                Fail::business('swap_closed', 'This request has already been decided.', 409);
            }
            $current = $this->approverFor($requester, $swap->work_date); // recomputed on decision
            abort_unless($this->mayDecide($user, $swap, $requester, $current), 403, 'You are not the approver for this request.');
            $counterpart = $swap->counterpart_id ? Employee::query()->find($swap->counterpart_id) : null;
            if (! $approve) {
                $swap->update(['status' => 'rejected', 'decided_at' => now(), 'decision_comment' => $comment, 'approver_user_id' => $user->id]);
                $this->finish($swap, $requester, $counterpart, 'rejected', $user);

                return $swap;
            }
            SchedulingEffects::assertUnlocked($requester->id, $swap->work_date);
            $counterpartDate = $swap->counterpart_date ?: $swap->work_date;
            if ($counterpart) {
                SchedulingEffects::assertUnlocked($counterpart->id, $counterpartDate);
                $requesterSchedule = $this->resolver->resolve($requester->id, $swap->work_date);
                $counterpartSchedule = $this->resolver->resolve($counterpart->id, $counterpartDate);
                abort_if($requesterSchedule->kind !== 'working' || $requesterSchedule->shiftId !== $swap->from_shift_id || $counterpartSchedule->kind !== 'working' || $counterpartSchedule->shiftId !== $swap->to_shift_id,
                    409, 'One of the shifts changed after this request was submitted. Ask the colleague to submit a new swap.');
            }
            $violations = $this->violations($requester->id, $counterpart?->id, $swap->work_date, $counterpartDate, $swap->from_shift_id, $swap->to_shift_id, $swap->type);
            if ($violations) {
                Fail::business('swap_violates_rules', 'Approving this would now break a scheduling rule: '.$violations[0]['message'], 422, ['conflicts' => $violations]);
            }
            $reason = "Shift {$swap->type} approved (request {$swap->uuid})";
            $this->rosters->writePublished($requester->id, $swap->work_date, 'shift', $swap->to_shift_id, $reason, $user);
            if ($counterpart) {
                $this->rosters->writePublished($counterpart->id, $counterpartDate, 'shift', $swap->from_shift_id, $reason, $user);
            }
            $swap->update(['status' => 'approved', 'decided_at' => now(), 'decision_comment' => $comment, 'approver_user_id' => $user->id]);
            $this->finish($swap, $requester, $counterpart, 'approved', $user);
            SchedulingEffects::recalculate([$requester->id], $swap->work_date, $swap->work_date, $user->id, 'swap:'.$swap->id.':requester');
            if ($counterpart) {
                SchedulingEffects::recalculate([$counterpart->id], $counterpartDate, $counterpartDate, $user->id, 'swap:'.$swap->id.':counterpart');
            }

            return $swap;
        });
    }

    /** Requests the viewer can act on (as approver, delegate or counterpart). */
    public function inboxFilter($query, User $user)
    {
        $delegators = $this->delegatorsOf($user);
        $mine = app(\App\Domain\Access\DataScope::class)->ownEmployeeIds($user);

        return $query->where(fn ($q) => $q->where('approver_user_id', $user->id)->orWhereIn('approver_user_id', $delegators ?: [0])->orWhereIn('counterpart_id', $mine ?: [0])->orWhereIn('requester_id', $mine ?: [0]));
    }

    // ---- helpers -------------------------------------------------------------------------------------------------------------

    private function assertEligible(Employee $a, Employee $b, string $date, string $counterpartDate, array $cfg): void
    {
        $x = $a->assignmentOn($date);
        $y = $b->assignmentOn($counterpartDate);
        foreach (['same_team_only' => ['team_id', 'team'], 'same_department_only' => ['department_id', 'department'], 'same_location_only' => ['location_id', 'location']] as $key => [$col, $label]) {
            if ($cfg[$key] && (($x->{$col} ?? null) === null || ($x->{$col} ?? null) !== ($y->{$col} ?? null))) {
                Fail::field('counterpart_uuid', "Swaps are only allowed with someone in your own {$label}.");
            }
        }
    }

    private function assertRestrictions(Employee $requester, $mine, string $date, array $cfg, bool $checkMonthlyLimit = true): void
    {
        $today = SchedulingEffects::today();
        if ($date < $today) {
            Fail::field('work_date', 'You cannot change a shift in the past.');
        }
        $start = $mine->start ?? \App\Domain\Attendance\Support\TimeZones::localToUtc($date, '00:00', $mine->timezone);
        if ($cfg['min_notice_hours'] > 0 && now()->addHours((int) $cfg['min_notice_hours'])->greaterThan($start)) {
            Fail::field('work_date', "Requests need at least {$cfg['min_notice_hours']} hours' notice before the shift starts.");
        }
        $month = substr($date, 0, 7);
        if ($checkMonthlyLimit && $cfg['max_per_month'] !== null) {
            $used = ShiftSwapRequest::query()->where('requester_id', $requester->id)->whereNotIn('status', ['rejected', 'withdrawn', 'cancelled'])->where('work_date', 'like', $month.'%')->count();
            if ($used >= (int) $cfg['max_per_month']) {
                Fail::field('work_date', "You have already used your {$cfg['max_per_month']} shift change request(s) for {$month}.");
            }
        }
        SchedulingEffects::assertUnlocked($requester->id, $date);
    }

    /** Conflicts (rest, overlap, limits ...) the swapped schedule would produce. */
    private function violations(int $requesterId, ?int $counterpartId, string $date, string $counterpartDate, ?int $fromShift, ?int $toShift, string $type): array
    {
        $overlay = [$requesterId => [$date => ['entry_type' => 'shift', 'shift_id' => $toShift]]];
        if ($counterpartId) {
            $overlay[$counterpartId] = [$counterpartDate => ['entry_type' => 'shift', 'shift_id' => $fromShift]];
        }
        $out = [];
        foreach ($overlay as $employeeId => $days) {
            foreach ($days as $workDate => $entry) {
                $existing = array_map(fn ($c) => $c['employee_id'].'|'.$c['date'].'|'.$c['code'], $this->conflicts->detect([(int) $employeeId], $workDate, $workDate));
                // Only problems the swap would INTRODUCE count; pre-existing issues are not the employee's doing.
                $out = [...$out, ...array_values(array_filter($this->conflicts->detect([(int) $employeeId], $workDate, $workDate, [(int) $employeeId => [$workDate => $entry]]),
                    fn ($c) => ! in_array($c['employee_id'].'|'.$c['date'].'|'.$c['code'], $existing, true)))];
            }
        }

        return $out;
    }

    public function approverFor(Employee $requester, string $date): ?User
    {
        $manager = app(ReportingService::class)->getManager($requester, 'primary', $date);
        $userId = $manager ? DB::table('people')->where('tenant_id', app(TenantContext::class)->id)->where('id', $manager->person_id)->value('user_id') : null;

        return $userId ? User::query()->where('tenant_id', app(TenantContext::class)->id)->where('active', true)->find($userId) : null;
    }

    /** @return array<int,int> user ids that have delegated to this user today */
    private function delegatorsOf(User $user): array
    {
        $today = SchedulingEffects::today();

        return DB::table('approval_delegations')->where('tenant_id', $user->tenant_id)->where('delegate_user_id', $user->id)->where('active', true)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)
            ->get()->filter(fn ($d) => $d->kinds === null || in_array('shift_swap', json_decode($d->kinds, true) ?: [], true))->pluck('delegator_user_id')->map(fn ($v) => (int) $v)->all();
    }

    private function mayDecide(User $user, ShiftSwapRequest $swap, Employee $requester, ?User $current): bool
    {
        if (app(\App\Domain\Access\DataScope::class)->isSelf($user, $requester)) {
            return false;
        }
        $delegators = $this->delegatorsOf($user);
        $routed = array_filter([$current?->id, $swap->approver_user_id]);
        if (in_array($user->id, $routed, true) && $user->allows('attendance.approve')) {
            return true;
        }
        if (array_intersect($routed, $delegators)) {
            return true;
        }

        return ! $routed && $user->allows('attendance.manage') && $user->hasTenantScope();
    }

    private function routeToApprover(ShiftSwapRequest $swap, Employee $requester, ?User $approver): void
    {
        $name = $requester->person->display_name;
        $recipients = array_filter([$approver]);
        $today = SchedulingEffects::today();
        if ($approver) {
            foreach (DB::table('approval_delegations')->where('tenant_id', $approver->tenant_id)->where('delegator_user_id', $approver->id)->where('active', true)->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->pluck('delegate_user_id') as $id) {
                if ($u = User::query()->where('tenant_id', $approver->tenant_id)->find($id)) {
                    $recipients[] = $u;
                }
            }
        }
        foreach ($recipients as $u) {
            app(TaskService::class)->createOnce(['assignee_id' => $u->id, 'title' => "Approve shift {$swap->type} for {$name}", 'description' => "{$name} asked for a shift {$swap->type} on {$swap->work_date}. Reason: {$swap->reason}",
                'source_module' => 'attendance', 'source_entity' => 'shift_swap:'.$swap->uuid, 'priority' => 'normal', 'due_date' => $swap->work_date, 'action' => '/time-office/approvals?kind=shift_swap']);
        }
    }

    private function tell(Employee $to, string $title, string $body, string $action, ShiftSwapRequest $swap, ?string $taskSuffix): void
    {
        $userId = DB::table('people')->where('id', $to->person_id)->value('user_id');
        if (! $userId) {
            return;
        }
        app(NotificationService::class)->send((int) $userId, $title, $body, 'attendance', $action);
        if ($taskSuffix) {
            app(TaskService::class)->createOnce(['assignee_id' => (int) $userId, 'title' => $title, 'description' => $body, 'source_module' => 'attendance', 'source_entity' => 'shift_swap:'.$swap->uuid.':'.$taskSuffix,
                'due_date' => $swap->work_date, 'action' => $action]);
        }
    }

    private function closeTasks(ShiftSwapRequest $swap): void
    {
        $tasks = app(TaskService::class);
        $tasks->completeBySource('attendance', 'shift_swap:'.$swap->uuid);
        $tasks->completeBySource('attendance', 'shift_swap:'.$swap->uuid.':consent');
    }

    private function finish(ShiftSwapRequest $swap, Employee $requester, ?Employee $counterpart, string $outcome, User $user): void
    {
        $this->closeTasks($swap);
        $counterpartDate = $swap->counterpart_date ?: $swap->work_date;
        Audit::record($outcome, 'attendance', 'shift_swap:'.$swap->uuid, ['by' => $user->id]);
        $this->events->record($outcome === 'approved' ? 'ShiftSwapApproved' : 'ShiftSwapRejected', ['employee' => $requester, 'summary' => "Shift {$swap->type} {$outcome} for {$swap->work_date}", 'subject' => ['shift_swap', $swap->id], 'effective_date' => $swap->work_date, 'payload' => ['swap' => $swap->uuid, 'counterpart_date' => $counterpartDate]]);
        $this->tell($requester, "Shift {$swap->type} {$outcome}", "Your request for {$swap->work_date}".($counterpart && $counterpartDate !== $swap->work_date ? " in exchange for {$counterpartDate}" : '')." was {$outcome}.", '/attendance?tab=schedule', $swap, null);
        if ($counterpart && $outcome === 'approved') {
            $this->events->record('ShiftChanged', ['employee' => $counterpart, 'summary' => "Shift swapped on {$counterpartDate}", 'effective_date' => $counterpartDate, 'payload' => ['swap' => $swap->uuid]]);
            $this->tell($counterpart, 'Your shift changed', "Your shift on {$counterpartDate} changed after an approved swap.", '/attendance?tab=schedule', $swap, null);
        }
        if ($outcome === 'approved') {
            $this->events->record('ShiftChanged', ['employee' => $requester, 'summary' => "Shift changed on {$swap->work_date} (swap)", 'effective_date' => $swap->work_date, 'payload' => ['swap' => $swap->uuid]]);
        }
    }

    public function present(ShiftSwapRequest $s): array
    {
        $codes = Shift::query()->pluck('code', 'id');
        $names = DB::table('employees')->join('people', 'people.id', '=', 'employees.person_id')->whereIn('employees.id', array_filter([$s->requester_id, $s->counterpart_id]))->pluck('people.display_name', 'employees.id');

        return ['uuid' => $s->uuid, 'type' => $s->type, 'status' => $s->status, 'work_date' => $s->work_date, 'counterpart_date' => $s->counterpart_date, 'requester' => $names[$s->requester_id] ?? null, 'counterpart' => $s->counterpart_id ? ($names[$s->counterpart_id] ?? null) : null,
            'from_shift' => $s->from_shift_id ? ($codes[$s->from_shift_id] ?? null) : null, 'to_shift' => $s->to_shift_id ? ($codes[$s->to_shift_id] ?? null) : null, 'reason' => $s->reason,
            'decision_comment' => $s->decision_comment, 'decided_at' => $s->decided_at?->toIso8601String(), 'created_at' => $s->created_at?->toIso8601String()];
    }
}
