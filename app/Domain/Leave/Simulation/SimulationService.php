<?php

namespace App\Domain\Leave\Simulation;

use App\Domain\Leave\Contracts\Dto\DurationQuery;
use App\Domain\Leave\Contracts\DurationCalculator;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveSimulationRun;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Support\Units;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Policy Sandbox, Entitlement Simulator, Request Impact Preview, Leave Forecast and Policy Difference Viewer.
 *
 * READ-ONLY with respect to leave data: the only thing ever written is a row in leave_simulation_runs (so a simulation can be
 * re-opened). Nothing here touches the ledger, accounts, requests or policies. Real engines are used when bound
 * (EntitlementEngine, DurationCalculator, LeavePolicyResolver) and degrade to explicit "unavailable" notes otherwise.
 */
final class SimulationService
{
    public const MAX_EMPLOYEES = 200;

    public function __construct(private BalanceService $balances) {}

    /**
     * Policy Sandbox: what would a DRAFT rule document do to the people it would apply to. input:
     *   rules (draft per-type rule document) | policy_version_id + leave_type_id, employee_uuids[] (<= 200), period_start, period_end
     * Compares with the live engine entitlement when available.
     */
    public function sandbox(User $actor, array $input, array $employeeIds): array
    {
        $start = $this->date($input, 'period_start');
        $end = $this->date($input, 'period_end');
        $typeId = (int) ($input['leave_type_id'] ?? 0);
        abort_unless($typeId && DB::table('leave_types')->where('tenant_id', LeaveOps::tenantId())->where('id', $typeId)->exists(), 422, 'Choose a leave type of this workspace.');
        $rules = $input['rules'] ?? null;
        $versionId = isset($input['policy_version_id']) ? (int) $input['policy_version_id'] : null;
        if ($rules === null && $versionId) {
            $row = DB::table('leave_policy_type_rules')->where('tenant_id', LeaveOps::tenantId())->where('policy_version_id', $versionId)->where('leave_type_id', $typeId)->value('rules');
            abort_unless($row, 422, 'That policy version has no rules for this leave type.');
            $rules = json_decode($row, true);
        }
        if (! is_array($rules)) {
            throw ValidationException::withMessages(['rules' => 'Provide a draft rule document or a policy version.']);
        }
        $rows = [];
        $totals = ['draft_entitlement' => '0.0000', 'current_entitlement' => '0.0000', 'carry_forward' => '0.0000', 'lapsed' => '0.0000'];
        $engine = LeaveOps::engine();
        foreach (array_slice($employeeIds, 0, self::MAX_EMPLOYEES) as $id) {
            $employee = DB::table('employees as e')->join('people as p', 'p.id', '=', 'e.person_id')->where('e.id', $id)->first(['e.id', 'e.uuid', 'e.joined_on', 'p.display_name']);
            if (! $employee) {
                continue;
            }
            $draft = RuleEvaluator::entitlement($rules, substr((string) $employee->joined_on, 0, 10), $start, $end);
            $available = $this->availableAt($id, $typeId, $end);
            $carry = RuleEvaluator::carryForward($rules, $available, Units::add($draft['units'], '0'));
            $current = null;
            if ($engine) {
                try {
                    $current = Units::normalize($engine->entitlementFor($id, $typeId, $start, $end)['units'] ?? '0');
                } catch (\Throwable) {
                    $current = null;
                }
            }
            $rows[] = ['employee_uuid' => $employee->uuid, 'name' => $employee->display_name, 'draft_entitlement' => $draft['units'], 'draft_lines' => $draft['lines'], 'current_entitlement' => $current,
                'difference' => $current === null ? null : Units::sub($draft['units'], $current), 'balance_at_period_end' => $available, 'carry_forward' => $carry['carried'], 'lapsed' => $carry['lapsed'], 'closing_cap_applied' => $carry['capped'], 'carry_lines' => $carry['lines']];
            $totals['draft_entitlement'] = Units::add($totals['draft_entitlement'], $draft['units']);
            $totals['current_entitlement'] = Units::add($totals['current_entitlement'], $current ?? '0');
            $totals['carry_forward'] = Units::add($totals['carry_forward'], $carry['carried']);
            $totals['lapsed'] = Units::add($totals['lapsed'], $carry['lapsed']);
        }

        return $this->record($actor, 'policy_sandbox', $input, ['evaluator' => 'rule_document', 'live_engine_available' => $engine !== null, 'period' => [$start, $end], 'employees' => count($rows),
            'truncated' => count($employeeIds) > self::MAX_EMPLOYEES, 'totals' => $totals, 'rows' => $rows, 'note' => 'Draft numbers follow the rule document conventions; nothing was saved to any policy or balance.'], $versionId);
    }

    /** Entitlement Simulator for one employee: what the engine grants (and why) for a period, plus an optional accrual window. */
    public function entitlement(User $actor, object $employee, array $input): array
    {
        $typeId = (int) ($input['leave_type_id'] ?? 0);
        abort_unless($typeId && DB::table('leave_types')->where('tenant_id', LeaveOps::tenantId())->where('id', $typeId)->exists(), 422, 'Choose a leave type of this workspace.');
        $start = $this->date($input, 'period_start');
        $end = $this->date($input, 'period_end');
        $engine = LeaveOps::engine();
        $result = ['engine_available' => $engine !== null, 'period' => [$start, $end], 'entitlement' => null, 'accrual' => null, 'rules' => null, 'source' => null];
        $rules = null;
        if ($resolver = LeaveOps::resolver()) {
            try {
                $found = $resolver->rulesFor((int) $employee->id, $typeId, $start);
                $rules = $found['rules'] ?? null;
                $result['rules'] = $found ? ['version_id' => $found['version_id'], 'reason' => $found['reason'] ?? null] : ['granted' => false];
            } catch (\Throwable) {
            }
        }
        if ($engine) {
            $result['entitlement'] = $engine->entitlementFor((int) $employee->id, $typeId, $start, $end);
            if (! empty($input['accrual_from']) && ! empty($input['accrual_to'])) {
                $result['accrual'] = $engine->accrualFor((int) $employee->id, $typeId, $this->date($input, 'accrual_from'), $this->date($input, 'accrual_to'));
            }
            $result['source'] = 'entitlement_engine';
        } elseif ($rules !== null) {
            $result['entitlement'] = RuleEvaluator::entitlement($rules, substr((string) $employee->joined_on, 0, 10), $start, $end);
            $result['source'] = 'rule_document';
        } else {
            $result['note'] = 'The entitlement engine and the policy resolver are not available yet, so nothing can be simulated.';
        }

        return $this->record($actor, 'entitlement', $input + ['employee_uuid' => $employee->uuid], $result, null);
    }

    /** Request Impact Preview: charge, projected balance, team overlap and lock status for a prospective request. Writes nothing but the run. */
    public function requestImpact(User $actor, object $employee, array $input): array
    {
        $typeId = (int) ($input['leave_type_id'] ?? 0);
        abort_unless($typeId && DB::table('leave_types')->where('tenant_id', LeaveOps::tenantId())->where('id', $typeId)->exists(), 422, 'Choose a leave type of this workspace.');
        $start = $this->date($input, 'start_date');
        $end = $this->date($input, 'end_date');
        if ($end < $start) {
            throw ValidationException::withMessages(['end_date' => 'The end date cannot be before the start date.']);
        }
        $duration = null;
        $calc = LeaveOps::lazy(DurationCalculator::class);
        if ($calc) {
            $duration = $calc->calculate(new DurationQuery((int) $employee->id, $typeId, $start, $end, $input['portion'] ?? 'full_day', $input['start_time'] ?? null, $input['end_time'] ?? null))->toArray();
        }
        $charge = $duration['charged_units'] ?? (isset($input['units']) ? Units::normalize($input['units']) : null);
        $account = LeaveAccount::query()->where('employee_id', $employee->id)->where('leave_type_id', $typeId)->where('status', 'open')->where('period_start', '<=', $start)->where('period_end', '>=', $start)->first();
        $projected = null;
        if ($account && $charge !== null) {
            $current = $this->balances->balance($account, $start, true);
            $after = Units::sub($current->available, $charge);
            $limit = Units::normalize($account->getRawOriginal('negative_limit'));
            $projected = ['available_on_start' => $current->available, 'charge' => $charge, 'balance_after' => $after, 'negative_limit' => $limit, 'sufficient' => Units::cmp($after, Units::neg($limit)) >= 0];
        }
        $teamOverlap = $this->teamOverlap((int) $employee->id, $start, $end);
        $lock = app(\App\Domain\Leave\Contracts\LeaveLockGuard::class)->lockFor((int) $employee->id, $start);

        return $this->record($actor, 'request_impact', $input + ['employee_uuid' => $employee->uuid], ['duration' => $duration, 'duration_engine_available' => $calc !== null, 'account_found' => $account !== null, 'projected' => $projected,
            'team_overlap' => $teamOverlap, 'locked' => $lock ? ['scope' => $lock['scope_label'], 'reason' => $lock['reason']] : null,
            'verdict' => match (true) { $lock !== null => 'blocked_by_lock', $projected !== null && ! $projected['sufficient'] => 'insufficient_balance', $charge === null => 'unknown_charge', default => 'ok' }], null);
    }

    /** Leave Forecast: month-by-month balance projection (scheduled leave and future-dated grants are in the ledger; accrual comes from the engine when bound). */
    public function forecast(object $employee, ?int $leaveTypeId, int $months, ?User $actor = null): array
    {
        $months = max(1, min(24, $months));
        $today = LeaveOps::today();
        $engine = LeaveOps::engine();
        $series = [];
        $accounts = LeaveAccount::query()->where('employee_id', $employee->id)->when($leaveTypeId, fn ($q) => $q->where('leave_type_id', $leaveTypeId))->where('status', 'open')->orderBy('leave_type_id')->get();
        foreach ($accounts as $account) {
            $type = DB::table('leave_types')->where('id', $account->leave_type_id)->value('code');
            $cursor = CarbonImmutable::parse($today);
            $accrued = '0.0000';
            $points = [];
            $periodEnd = substr((string) $account->getRawOriginal('period_end'), 0, 10);
            for ($i = 1; $i <= $months; $i++) {
                $to = $cursor->addMonthsNoOverflow($i)->endOfMonth()->toDateString();
                $from = $cursor->addMonthsNoOverflow($i)->startOfMonth()->toDateString();
                if ($from > $periodEnd) {
                    break;
                }
                $to = min($to, $periodEnd);
                $monthAccrual = '0.0000';
                if ($engine) {
                    try {
                        $monthAccrual = Units::normalize($engine->accrualFor((int) $employee->id, (int) $account->leave_type_id, $from, $to)['units'] ?? '0');
                    } catch (\Throwable) {
                    }
                }
                $accrued = Units::add($accrued, $monthAccrual);
                $ledgerBalance = $this->balances->balance($account, $to, true)->available;
                $points[] = ['date' => $to, 'ledger_available' => $ledgerBalance, 'projected_accrual' => $accrued, 'projected_available' => Units::add($ledgerBalance, $accrued)];
            }
            $expiring = array_values(array_filter($this->balances->lots($account, $today), fn ($l) => $l['expires_on'] !== null && $l['expires_on'] <= $periodEnd));
            $series[] = ['account_uuid' => $account->uuid, 'leave_type' => $type, 'period_end' => $periodEnd, 'points' => $points,
                'expiring_lots' => array_map(fn ($l) => ['units' => $l['remaining'], 'expires_on' => $l['expires_on']], $expiring)];
        }

        $out = ['employee_uuid' => $employee->uuid, 'as_of' => $today, 'engine_available' => $engine !== null, 'accounts' => $series,
            'method' => 'ledger balance on each date (scheduled leave and dated grants included) plus engine accrual for the remaining months'];
        if ($actor) {
            $this->record($actor, 'forecast', ['employee_uuid' => $employee->uuid, 'leave_type_id' => $leaveTypeId, 'months' => $months], ['accounts' => count($series)], null);
        }

        return $out;
    }

    /** Policy Difference Viewer: path-level diff between two policy versions (policy rules + per type rules). */
    public function diff(User $actor, int $fromVersionId, int $toVersionId): array
    {
        $tenant = LeaveOps::tenantId();
        $load = function (int $id) use ($tenant) {
            $v = DB::table('leave_policy_versions as v')->join('leave_policies as p', 'p.id', '=', 'v.policy_id')->where('v.tenant_id', $tenant)->where('v.id', $id)->first(['v.id', 'v.version', 'v.status', 'v.rules', 'p.name']);
            abort_unless($v, 404, 'Policy version not found.');
            $types = DB::table('leave_policy_type_rules as r')->join('leave_types as t', 't.id', '=', 'r.leave_type_id')->where('r.tenant_id', $tenant)->where('r.policy_version_id', $id)->get(['t.code', 'r.rules']);

            return ['meta' => ['id' => $v->id, 'policy' => $v->name, 'version' => $v->version, 'status' => $v->status], 'policy_rules' => json_decode($v->rules ?? 'null', true) ?: [],
                'types' => $types->mapWithKeys(fn ($t) => [$t->code => json_decode($t->rules, true) ?: []])->all()];
        };
        $a = $load($fromVersionId);
        $b = $load($toVersionId);
        $changes = [];
        foreach (RuleEvaluator::diff($a['policy_rules'], $b['policy_rules']) as $c) {
            $changes[] = ['scope' => 'policy'] + $c;
        }
        foreach (array_unique(array_merge(array_keys($a['types']), array_keys($b['types']))) as $code) {
            if (! isset($a['types'][$code])) {
                $changes[] = ['scope' => 'type:'.$code, 'path' => '*', 'change' => 'added', 'from' => null, 'to' => 'leave type added to the policy'];
            } elseif (! isset($b['types'][$code])) {
                $changes[] = ['scope' => 'type:'.$code, 'path' => '*', 'change' => 'removed', 'from' => 'leave type granted', 'to' => null];
            } else {
                foreach (RuleEvaluator::diff($a['types'][$code], $b['types'][$code]) as $c) {
                    $changes[] = ['scope' => 'type:'.$code] + $c;
                }
            }
        }

        return $this->record($actor, 'policy_diff', ['from' => $fromVersionId, 'to' => $toVersionId], ['from' => $a['meta'], 'to' => $b['meta'], 'changes' => $changes, 'identical' => $changes === []], $toVersionId);
    }

    // ------------------------------------------------------------------------------------------------------------------------

    private function record(User $actor, string $kind, array $input, array $result, ?int $versionId): array
    {
        $run = LeaveSimulationRun::create(['kind' => $kind, 'input' => $input, 'result' => $result, 'policy_version_id' => $versionId, 'requested_by' => $actor->id]);

        return ['simulation_uuid' => $run->uuid, 'kind' => $kind, 'read_only' => true] + $result;
    }

    private function availableAt(int $employeeId, int $typeId, string $date): string
    {
        $account = LeaveAccount::query()->where('employee_id', $employeeId)->where('leave_type_id', $typeId)->where('period_start', '<=', $date)->orderByDesc('period_start')->first();

        return $account ? $this->balances->balance($account, $date, true)->available : '0.0000';
    }

    /** @return array{count:int,people:array<int,array<string,mixed>>} approved/pending leave of the employee's reporting peers that overlaps (names only for the same reporting line) */
    private function teamOverlap(int $employeeId, string $start, string $end): array
    {
        $tenant = LeaveOps::tenantId();
        $today = LeaveOps::today();
        $manager = DB::table('employee_reporting_relationships')->where('tenant_id', $tenant)->where('type', 'primary')->where('employee_id', $employeeId)->where('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->value('manager_id');
        if (! $manager) {
            return ['count' => 0, 'people' => []];
        }
        $peers = DB::table('employee_reporting_relationships')->where('tenant_id', $tenant)->where('type', 'primary')->where('manager_id', $manager)->where('employee_id', '!=', $employeeId)->where('effective_from', '<=', $today)->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $today))->pluck('employee_id');
        $rows = DB::table('leave_requests as r')->join('employees as e', 'e.id', '=', 'r.employee_id')->join('people as p', 'p.id', '=', 'e.person_id')->where('r.tenant_id', $tenant)->whereIn('r.employee_id', $peers)
            ->whereIn('r.status', ['pending', 'approved'])->where('r.start_date', '<=', $end)->where('r.end_date', '>=', $start)->get(['e.uuid', 'p.display_name', 'r.start_date', 'r.end_date', 'r.status']);

        return ['count' => $rows->count(), 'people' => $rows->take(10)->map(fn ($r) => ['employee_uuid' => $r->uuid, 'name' => $r->display_name, 'start_date' => substr((string) $r->start_date, 0, 10), 'end_date' => substr((string) $r->end_date, 0, 10), 'status' => $r->status])->all()];
    }

    private function date(array $input, string $key): string
    {
        $v = $input[$key] ?? null;
        if (! is_string($v) || LeaveOps::date($v) === null) {
            throw ValidationException::withMessages([$key => 'Use the format YYYY-MM-DD.']);
        }

        return $v;
    }
}
