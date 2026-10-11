<?php

namespace App\Domain\Leave\Requests;

use App\Domain\Attendance\Requests\LocalInstant;
use App\Domain\Leave\Contracts\Dto\DurationQuery;
use App\Domain\Leave\Contracts\Dto\DurationResult;
use App\Domain\Leave\Contracts\DurationCalculator;
use App\Domain\Leave\Duration\DurationEngine;
use App\Domain\Leave\Duration\RuleSet;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestSegment;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Restrictions\RestrictionEngine;
use App\Domain\Leave\Restrictions\TeamScope;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Everything that can be known about a request BEFORE it is created, without writing anything: duration and charge,
 * conflicts, projected balance, approval route and team impact. Used by the "request impact preview" endpoint and by
 * submit (which then persists exactly this plan), so what the employee previews is what gets submitted.
 */
final class RequestPlanner
{
    public function __construct(
        private DurationCalculator $duration,
        private RestrictionEngine $restrictions,
        private BalanceService $balances,
        private AccountLocator $accounts,
        private LeaveApprovers $approvers,
        private TeamScope $teams,
    ) {}

    /**
     * @param  array<string,mixed>  $in
     * @return array<string,mixed> plan (see keys at the end)
     */
    public function plan(User $actor, Employee $employee, array $in, ?LeaveRequest $parent = null, int $documents = 0): array
    {
        $data = $this->validate($in);
        $type = LeaveType::query()->where('uuid', $data['leave_type'])->where('active', true)->first();
        if (! $type) {
            throw ValidationException::withMessages(['leave_type' => 'Choose an available leave type.']);
        }
        if (LeaveSettings::get('requests.require_reason', false) && trim((string) ($data['reason'] ?? '')) === '') {
            throw ValidationException::withMessages(['reason' => 'Please give a short reason.']);
        }
        $delegate = null;
        if (! empty($data['delegate_employee'])) {
            $delegate = Employee::query()->where('uuid', $data['delegate_employee'])->first();
            if (! $delegate || $delegate->id === $employee->id) {
                throw ValidationException::withMessages(['delegate_employee' => 'Choose a colleague (not yourself) to cover for you.']);
            }
        }
        $query = new DurationQuery($employee->id, $type->id, $data['start_date'], $data['end_date'], $data['portion'], $data['start_time'] ?? null, $data['end_time'] ?? null, $data['timezone'] ?? null,
            null, $parent?->id, null);
        $duration = $this->duration->calculate($query);
        $rules = RuleSet::load($employee->id, $type->id, $data['start_date']);
        $conflicts = $duration->valid ? $this->restrictions->evaluate($employee, $type, $duration, $rules, $parent?->id, $delegate?->id, $documents) : [];
        $projection = $duration->valid ? $this->project($employee, $type, $duration, $parent, $conflicts) : ['tracked' => (bool) $type->tracks_balance, 'accounts' => []];
        $summary = RestrictionEngine::summary($conflicts);

        $roles = $this->approvers->roles($employee, $data['start_date']);
        $reasons = [];
        if ($summary['escalate']) {
            $reasons[] = 'A policy rule or restriction asks for HR review.';
        }
        $hrAbove = $rules->rules['approvals']['hr_above_units'] ?? null;
        if ($hrAbove !== null && $duration->valid && Units::cmp($duration->chargedUnits, $hrAbove) > 0) {
            $reasons[] = 'The request is longer than '.rtrim(rtrim(Units::normalize($hrAbove), '0'), '.').' so HR also approves it.';
        }
        if ($type->is_sensitive && ! empty($rules->rules['approvals']['hr_for_sensitive'])) {
            $reasons[] = 'Sensitive leave is approved by HR.';
        }
        if ($reasons && ! in_array('hr', $roles, true)) {
            $roles[] = 'hr';
        }
        $exclude = ! $this->approvers->userIdOf($employee) || $this->approvers->userIdOf($employee) !== $actor->id ? [$actor->id] : [];
        $first = $this->approvers->candidates($employee, $data['start_date'], 1, $exclude, $roles);

        return ['data' => $data, 'type' => $type, 'employee' => $employee, 'delegate' => $delegate, 'duration' => $duration, 'rules' => $rules, 'conflicts' => $conflicts, 'summary' => $summary,
            'projection' => $projection, 'roles' => $roles, 'escalation_reasons' => $reasons, 'first_approvers' => $first, 'statutory' => $type->is_statutory || $type->category === 'statutory'];
    }

    /** The preview API body: nothing here writes. */
    public function preview(array $plan, User $viewer): array
    {
        /** @var DurationResult $d */
        $d = $plan['duration'];
        /** @var Employee $employee */
        $employee = $plan['employee'];
        $team = [];
        if ($d->valid) {
            foreach (array_slice(array_values(array_unique(array_map(fn ($s) => $s->date, array_filter($d->segments, fn ($s) => $s->charged)))), 0, 31) as $date) {
                $peers = array_values(array_diff($this->teams->peers($employee->id, 'department', $date), [$employee->id]));
                $away = $this->teams->away($peers, $date);
                $team[] = ['date' => $date, 'team_size' => count($peers) + 1, 'away_already' => round(array_sum($away), 2), 'available_after' => max(0, round(count($peers) + 1 - 1 - array_sum($away), 2))];
            }
        }
        $blocked = ! $d->valid || $plan['summary']['blocked'];

        return ['can_submit' => ! $blocked && $plan['first_approvers']->isNotEmpty(), 'duration' => $d->toArray(), 'conflicts' => $plan['conflicts'], 'balance' => $plan['projection'],
            'approval' => ['roles' => $plan['roles'], 'steps' => count($plan['roles']), 'escalation_reasons' => $plan['escalation_reasons'], 'first_approvers' => $plan['first_approvers']->map(fn (User $u) => ['name' => $u->name])->values()->all(),
                'has_approver' => $plan['first_approvers']->isNotEmpty()],
            'documents' => $this->requiredDocuments($plan), 'team_impact' => $team, 'statutory' => $plan['statutory']];
    }

    /** @return array<int,array<string,mixed>> */
    private function requiredDocuments(array $plan): array
    {
        return array_values(array_map(fn ($c) => ['kind' => $c['detail']['document_kind'] ?? 'supporting', 'submit_within_days' => $c['detail']['submit_within_days'] ?? null, 'message' => $c['message']],
            array_filter($plan['conflicts'], fn ($c) => $c['kind'] === 'document')));
    }

    /** Projected balance per ledger account the request would draw on (negative limit honoured, a modified request's own units added back). */
    private function project(Employee $employee, LeaveType $type, DurationResult $d, ?LeaveRequest $parent, array &$conflicts): array
    {
        if (! $type->tracks_balance) {
            return ['tracked' => false, 'accounts' => [], 'note' => $type->name.' does not use a balance.'];
        }
        $needs = [];
        $missing = [];
        foreach ($d->segments as $s) {
            if (! $s->charged || Units::isZero($s->units)) {
                continue;
            }
            $account = $this->accounts->forDate($employee->id, $type->id, $s->date);
            if (! $account) {
                $missing[$s->date] = true;

                continue;
            }
            $needs[$account->id]['account'] = $account;
            $needs[$account->id]['units'] = Units::add($needs[$account->id]['units'] ?? '0', $s->units);
        }
        foreach ($missing as $date => $_) {
            $conflicts[] = ['kind' => 'balance', 'severity' => 'block', 'message' => "There is no open {$type->name} balance covering $date. Ask HR to open your leave account for that period.", 'detail' => ['date' => $date]];
            break;
        }
        $accounts = [];
        $statutory = $type->is_statutory || $type->category === 'statutory';
        foreach ($needs as $accountId => $need) {
            $account = $need['account'];
            $balance = $this->balances->balance($account, TenantClock::today($employee->timezone ?: null));
            $back = '0.0000';
            if ($parent) {
                $back = Units::sum(LeaveRequestSegment::query()->where('request_id', $parent->id)->where('account_id', $accountId)->where('status', 'active')->where('charged', true)->get()->map(fn ($s) => $s->getRawOriginal('units')));
            }
            $before = Units::add($balance->available, $back);
            $after = Units::sub($before, $need['units']);
            $limit = Units::normalize($account->getRawOriginal('negative_limit'));
            $ok = Units::cmp($after, Units::neg($limit)) >= 0;
            if (! $ok) {
                $conflicts[] = ['kind' => 'balance', 'severity' => $statutory ? 'escalate' : 'block', 'detail' => ['available' => $before, 'needed' => $need['units'], 'negative_limit' => $limit],
                    'message' => "Not enough {$type->name} balance: {$before} available, {$need['units']} needed".(Units::cmp($limit, '0') > 0 ? " (down to -$limit is allowed)." : '.').($statutory ? ' This is statutory leave, so it is sent for HR review instead of being refused.' : '')];
            }
            $accounts[] = ['account' => $account->uuid, 'period_start' => $account->period_start, 'period_end' => $account->period_end, 'available_before' => $before, 'requested' => $need['units'],
                'projected' => $after, 'negative_limit' => $limit, 'sufficient' => $ok];
        }

        return ['tracked' => true, 'accounts' => $accounts, 'unit' => $type->unit === 'hour' ? 'hours' : 'days'];
    }

    /** @return array<string,mixed> */
    private function validate(array $in): array
    {
        $v = Validator::make($in, [
            'leave_type' => 'required|string|max:64', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d',
            'portion' => 'nullable|in:'.implode(',', DurationEngine::PORTIONS), 'start_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'], 'end_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}(:\d{2})?$/'],
            'timezone' => 'nullable|string|max:64', 'reason' => 'nullable|string|max:2000', 'delegate_employee' => 'nullable|string|max:64', 'handover_notes' => 'nullable|string|max:4000',
        ]);
        $data = $v->validate();
        if (! LocalInstant::validDate($data['start_date'])) {
            throw ValidationException::withMessages(['start_date' => 'Enter a valid date.']);
        }
        $data['end_date'] = $data['end_date'] ?? $data['start_date'];
        $data['portion'] = $data['portion'] ?? 'full_day';

        return $data;
    }
}
