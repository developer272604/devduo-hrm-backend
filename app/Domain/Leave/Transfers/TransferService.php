<?php

namespace App\Domain\Leave\Transfers;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveSettlementReference;
use App\Domain\Leave\Models\LeaveTransfer;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Support\Units;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee lifecycle handling for leave balances: transfer / policy change (country, entity, location, employee type), separation
 * (final accrual, encashment eligibility, negative balances, future leave) and rehire (restoration of preserved balances).
 *
 * Everything is DATA-driven (country-agnostic): the per-type rule document may carry
 *   transfer     {mode: preserve|transfer|convert|expire}
 *   encashment   {on_separation: bool, max_units}
 *   separation   {mode: expire|preserve}            what happens to what is left after encashment
 *   rehire       {restore: none|all|capped, cap, within_days}
 * (leave_types.config carries the same keys as a fallback). Money is never computed: encashment = units + a settlement reference.
 * plan() is read-only; apply() posts through LedgerService only, idempotently (key transfer:{uuid}:{account}:{step}).
 */
final class TransferService
{
    public const TRIGGERS = ['transfer', 'separation', 'rehire', 'policy_change'];

    public const MODES = ['preserve', 'transfer', 'convert', 'expire'];

    public function __construct(private BalanceService $balances, private LedgerService $ledger, private DomainEvents $events) {}

    /**
     * @param  array{mode?:string,previous_employee_id?:int,cancel_future?:bool}  $opts
     * @return array<string,mixed>
     */
    public function plan(int $employeeId, string $trigger, string $effectiveDate, array $opts = []): array
    {
        $this->assertInput($trigger, $effectiveDate, $opts['mode'] ?? null);
        $requested = $opts['mode'] ?? null;
        $mode = $requested ?? ($trigger === 'separation' ? 'expire' : 'preserve');
        $resolver = LeaveOps::resolver();
        $before = $this->policy($resolver, $employeeId, $this->before($effectiveDate));
        $after = $this->policy($resolver, $employeeId, $effectiveDate);
        $warnings = [];
        if (! $resolver) {
            $warnings[] = ['key' => 'policy_resolver_unavailable', 'message' => 'Policies could not be compared because the policy resolver is not available. Rules default to preserve.'];
        }
        $items = [];
        $sources = $trigger === 'rehire' && ! empty($opts['previous_employee_id']) ? (int) $opts['previous_employee_id'] : $employeeId;
        foreach ($this->openAccounts($sources, $effectiveDate) as $account) {
            $items[] = $this->item($trigger, $requested, $account, $employeeId, $effectiveDate, $resolver, $after['version_id'] ?? null);
        }
        $future = $trigger === 'rehire' ? [] : $this->futureLeave($employeeId, $effectiveDate);
        if ($trigger === 'separation' && ($engine = LeaveOps::engine())) {
            foreach ($items as &$it) {
                $accrual = $this->safe(fn () => $engine->accrualFor($employeeId, $it['leave_type_id'], substr($effectiveDate, 0, 4).'-01-01', $effectiveDate));
                $it['final_accrual'] = $accrual ? ['units' => Units::normalize($accrual['units'] ?? '0'), 'lines' => $accrual['lines'] ?? [], 'skipped' => $accrual['skipped'] ?? null] : null;
            }
            unset($it);
        }
        foreach ($items as $it) {
            if ($it['blocked']) {
                $warnings[] = ['key' => 'unmapped_leave_type', 'leave_type' => $it['leave_type_code'], 'message' => $it['note']];
            }
            if (Units::isNegative($it['available'])) {
                $warnings[] = ['key' => 'negative_balance', 'leave_type' => $it['leave_type_code'], 'units' => $it['available'], 'message' => 'The '.$it['leave_type_code'].' balance is negative ('.$it['available'].' units). It is reported for the settlement review; nothing is recovered automatically.'];
            }
        }
        if ($future) {
            $warnings[] = ['key' => 'future_leave', 'count' => count($future), 'message' => count($future).' leave request(s) start on or after '.$effectiveDate.'. Review or cancel them; applying this plan flags them for review.'];
        }

        return ['organization' => $this->organization($employeeId, $effectiveDate), 'trigger' => $trigger, 'effective_date' => $effectiveDate, 'balance_mode' => $mode, 'requested_mode' => $requested, 'from_policy' => $before, 'to_policy' => $after, 'policy_changed' => ($before['version_id'] ?? null) !== ($after['version_id'] ?? null),
            'items' => $items, 'future_leave' => $future, 'warnings' => $warnings, 'can_apply' => collect($items)->every(fn ($i) => ! $i['blocked']) || $mode === 'preserve'];
    }

    /** Stores (or refreshes) the preview row for the employee/trigger/date. Applied rows are immutable. */
    public function preview(int $employeeId, string $trigger, string $effectiveDate, array $opts = [], ?User $actor = null): LeaveTransfer
    {
        $plan = $this->plan($employeeId, $trigger, $effectiveDate, $opts);
        $row = LeaveTransfer::query()->where('employee_id', $employeeId)->where('trigger', $trigger)->where('effective_date', $effectiveDate)->first();
        if ($row && $row->status === 'applied') {
            throw ValidationException::withMessages(['trigger' => 'This '.$trigger.' on '.$effectiveDate.' has already been applied.']);
        }
        $attrs = ['balance_mode' => $plan['balance_mode'], 'status' => 'preview', 'plan' => $plan + ['previous_employee_id' => $opts['previous_employee_id'] ?? null], 'result' => null,
            'from_policy_version_id' => $plan['from_policy']['version_id'] ?? null, 'to_policy_version_id' => $plan['to_policy']['version_id'] ?? null, 'requested_by' => $actor?->id];

        return $row ? tap($row)->update($attrs) : LeaveTransfer::create($attrs + ['employee_id' => $employeeId, 'trigger' => $trigger, 'effective_date' => $effectiveDate]);
    }

    /** Applies a stored preview (recomputed from current balances, so the posted units are always current). */
    public function apply(LeaveTransfer $transfer, User $actor, ?string $mode = null): LeaveTransfer
    {
        if ($transfer->status === 'applied') {
            return $transfer;
        }
        if ($transfer->status === 'cancelled') {
            throw ValidationException::withMessages(['transfer' => 'This item was cancelled.']);
        }
        $date = substr((string) $transfer->effective_date, 0, 10);
        $opts = ['mode' => $mode ?: ($transfer->plan['requested_mode'] ?? null), 'previous_employee_id' => $transfer->plan['previous_employee_id'] ?? null];
        $plan = $this->plan($transfer->employee_id, $transfer->trigger, $date, $opts);
        if (! $plan['can_apply']) {
            throw ValidationException::withMessages(['mode' => 'Some leave types have no mapping for this mode. Add a leave type mapping or choose preserve.']);
        }
        $posted = [];
        DB::transaction(function () use ($transfer, $plan, $actor, $date, &$posted, $opts) {
            foreach ($plan['items'] as $item) {
                foreach ($item['actions'] as $i => $action) {
                    $posted[] = $this->post($transfer, $item, $action, $i, $date, $actor);
                }
            }
            $transfer->update(['status' => 'applied', 'balance_mode' => $plan['balance_mode'], 'plan' => $plan + ['previous_employee_id' => $opts['previous_employee_id']], 'result' => ['posted' => $posted, 'future_leave_flagged' => count($plan['future_leave'])]]);
        });
        Audit::record('applied', 'leave', 'leave_transfer:'.$transfer->uuid, ['trigger' => $transfer->trigger, 'mode' => $plan['balance_mode'], 'entries' => count($posted)]);
        if ($plan['future_leave']) {
            $this->events->record('leave.transfer.future_leave_review', ['employee' => $transfer->employee_id, 'subject' => ['leave_transfer', $transfer->id], 'actor_id' => $actor->id, 'effective_date' => $date,
                'summary' => count($plan['future_leave']).' future leave request(s) need review after '.$transfer->trigger, 'payload' => ['transfer_uuid' => $transfer->uuid, 'request_uuids' => array_column($plan['future_leave'], 'uuid'), 'trigger' => $transfer->trigger]]);
        }

        return $transfer->refresh();
    }

    public function cancel(LeaveTransfer $transfer): LeaveTransfer
    {
        if ($transfer->status !== 'preview') {
            throw ValidationException::withMessages(['transfer' => 'Only a preview can be cancelled; applied changes are corrected through ledger reversals.']);
        }
        $transfer->update(['status' => 'cancelled']);
        Audit::record('cancelled', 'leave', 'leave_transfer:'.$transfer->uuid, []);

        return $transfer;
    }

    // ------------------------------------------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function item(string $trigger, ?string $requested, LeaveAccount $account, int $targetEmployeeId, string $date, $resolver, ?int $toVersionId): array
    {
        $type = DB::table('leave_types')->where('id', $account->leave_type_id)->first(['id', 'code', 'name', 'config', 'is_statutory']);
        $available = $this->balances->balance($account, $date)->available;
        $rules = $this->rules($resolver, (int) $account->employee_id, (int) $account->leave_type_id, $date, $type);
        $item = ['account_id' => $account->id, 'account_uuid' => $account->uuid, 'employee_id' => (int) $account->employee_id, 'target_employee_id' => $targetEmployeeId, 'leave_type_id' => (int) $account->leave_type_id, 'leave_type_code' => $type->code, 'available' => $available,
            'negative' => Units::isNegative($available), 'actions' => [], 'blocked' => false, 'note' => null, 'statutory' => (bool) $type->is_statutory];
        $positive = Units::isNegative($available) || Units::isZero($available) ? '0.0000' : $available;

        if ($trigger === 'separation') {
            $enc = $rules['encashment'] ?? [];
            $eligible = ! empty($enc['on_separation']) && Units::cmp($positive, '0') > 0;
            $encash = $eligible ? (isset($enc['max_units']) ? Units::min($positive, $enc['max_units']) : $positive) : '0.0000';
            $item['encashment'] = ['eligible' => $eligible, 'units' => $encash, 'max_units' => $enc['max_units'] ?? null];
            $leftover = $requested ?? ($rules['separation']['mode'] ?? 'expire');
            if ($leftover !== 'preserve') {
                if (Units::cmp($encash, '0') > 0) {
                    $item['actions'][] = ['kind' => 'encashment', 'units' => $encash];
                }
                $rest = Units::sub($positive, $encash);
                if (Units::cmp($rest, '0') > 0) {
                    $item['actions'][] = ['kind' => 'expire', 'units' => $rest];
                }
            }
            $item['note'] = $eligible ? 'Eligible to encash '.$encash.' units (units only; payroll values it).' : 'Not eligible for encashment on separation.';

            return $item;
        }
        if ($trigger === 'rehire') {
            $restore = $rules['rehire'] ?? [];
            $how = $restore['restore'] ?? 'none';
            $mode = $requested ?? 'restore';
            $units = $how === 'all' ? $positive : ($how === 'capped' ? Units::min($positive, $restore['cap'] ?? '0') : '0.0000');
            $item['restore'] = ['policy' => $how, 'units' => $units];
            if (Units::cmp($units, '0') > 0 && $mode !== 'preserve') {
                $item['actions'][] = ['kind' => 'restore', 'units' => $units, 'target_employee_id' => $targetEmployeeId];
            }
            $item['note'] = Units::cmp($units, '0') > 0 ? 'Restores '.$units.' units from the previous employment.' : 'Nothing is restored for this leave type ('.$how.').';

            return $item;
        }
        // transfer / policy_change
        $effective = $requested ?? (in_array($rules['transfer']['mode'] ?? null, self::MODES, true) ? $rules['transfer']['mode'] : 'preserve');
        if ($effective === 'expire' && Units::cmp($positive, '0') > 0) {
            $item['actions'][] = ['kind' => 'expire', 'units' => $positive];
        } elseif (in_array($effective, ['transfer', 'convert'], true) && Units::cmp($positive, '0') > 0) {
            $map = DB::table('leave_type_mappings')->where('tenant_id', LeaveOps::tenantId())->where('from_leave_type_id', $account->leave_type_id)->whereIn('purpose', $effective === 'convert' ? ['conversion', 'transfer'] : ['transfer'])
                ->orderByRaw("CASE purpose WHEN 'conversion' THEN 0 ELSE 1 END")->first();
            if ($map) {
                $ratio = (string) $map->ratio;
                $item['actions'][] = ['kind' => 'move', 'units' => $positive, 'to_leave_type_id' => (int) $map->to_leave_type_id, 'ratio' => $ratio, 'converted_units' => Units::round(Units::mul($positive, $ratio))];
            } elseif ($effective === 'transfer') {
                $item['note'] = 'No leave type mapping for '.$type->code.'; the balance stays in its own account.';
            } else {
                $item['blocked'] = true;
                $item['note'] = 'No leave type mapping exists for '.$type->code.'. Add a mapping to convert, or choose preserve.';
            }
        }
        $item['note'] ??= $effective === 'preserve' ? 'Balance is preserved unchanged.' : null;

        return $item;
    }

    private function post(LeaveTransfer $t, array $item, array $action, int $index, string $date, User $actor): array
    {
        $base = 'transfer:'.$t->uuid.':'.$item['account_id'].':'.$index;
        $account = LeaveAccount::query()->findOrFail($item['account_id']);
        $meta = ['transfer' => $t->uuid, 'trigger' => $t->trigger, 'kind' => $action['kind']];
        $post = fn (string $type, string $bucket, string $units, int $accountId, string $suffix, ?string $reason = null) => $this->ledger->post(new LedgerPosting($accountId, $type, $bucket, $units, $date, $base.$suffix,
            referenceType: 'leave_transfer', referenceId: $t->uuid, reason: $reason, meta: $meta, createdBy: $actor->id, allowClosed: true));
        switch ($action['kind']) {
            case 'expire':
                $e = $post('expiry', 'expired', Units::neg($action['units']), $account->id, ':expire', 'Balance expired on '.$t->trigger);

                return ['kind' => 'expire', 'units' => $action['units'], 'entry' => $e->entry->uuid];
            case 'encashment':
                $ref = LeaveSettlementReference::create(['employee_id' => $account->employee_id, 'leave_type_id' => $account->leave_type_id, 'kind' => 'separation', 'units' => $action['units'], 'status' => 'approved',
                    'as_of' => $date, 'meta' => ['transfer' => $t->uuid, 'note' => 'Units only; valued by payroll.'], 'created_by' => $actor->id]);
                $e = $post('encashment', 'encashed', Units::neg($action['units']), $account->id, ':encash', 'Encashment on separation (reference '.$ref->uuid.')');
                $ref->update(['ledger_entry_id' => $e->entry->id]);

                return ['kind' => 'encashment', 'units' => $action['units'], 'entry' => $e->entry->uuid, 'settlement_reference' => $ref->uuid];
            case 'move':
                $target = $this->accountFor($account->employee_id, $action['to_leave_type_id'], $account, $date);
                $out = $post('transfer_out', 'carried', Units::neg($action['units']), $account->id, ':out', 'Moved to another leave type');
                $in = $post('transfer_in', 'carried', $action['converted_units'], $target->id, ':in', 'Moved from '.$item['leave_type_code'].' (ratio '.$action['ratio'].')');

                return ['kind' => 'move', 'units' => $action['units'], 'converted_units' => $action['converted_units'], 'out' => $out->entry->uuid, 'in' => $in->entry->uuid];
            case 'restore':
                $target = $this->accountFor($action['target_employee_id'], $account->leave_type_id, $account, $date);
                $out = $post('transfer_out', 'carried', Units::neg($action['units']), $account->id, ':out', 'Restored to the rehired employment');
                $in = $post('transfer_in', 'carried', $action['units'], $target->id, ':in', 'Restored from previous employment');

                return ['kind' => 'restore', 'units' => $action['units'], 'out' => $out->entry->uuid, 'in' => $in->entry->uuid];
        }
        throw new \LogicException('Unknown action '.$action['kind']);
    }

    /** The target employee's open account for the type covering the date; created (same period as the source) when missing. */
    private function accountFor(int $employeeId, int $leaveTypeId, LeaveAccount $like, string $date): LeaveAccount
    {
        $found = LeaveAccount::query()->where('employee_id', $employeeId)->where('leave_type_id', $leaveTypeId)->where('status', 'open')->where('period_start', '<=', $date)->where('period_end', '>=', $date)->orderByDesc('period_start')->first();
        if ($found) {
            return $found;
        }
        $covers = substr((string) $like->getRawOriginal('period_start'), 0, 10) <= $date && substr((string) $like->getRawOriginal('period_end'), 0, 10) >= $date;
        $start = $covers ? substr((string) $like->getRawOriginal('period_start'), 0, 10) : substr($date, 0, 4).'-01-01';
        $end = $covers ? substr((string) $like->getRawOriginal('period_end'), 0, 10) : substr($date, 0, 4).'-12-31';

        return LeaveAccount::create(['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'period_start' => $start, 'period_end' => $end, 'negative_limit' => '0', 'meta' => ['opened_by' => 'leave_transfer']]);
    }

    /** @return \Illuminate\Support\Collection<int,LeaveAccount> accounts whose period covers the date (or the latest open) */
    private function openAccounts(int $employeeId, string $date)
    {
        return LeaveAccount::query()->where('employee_id', $employeeId)->where('status', 'open')->where('period_start', '<=', $date)->where('period_end', '>=', $date)->orderBy('leave_type_id')->get();
    }

    /** @return array<int,array<string,mixed>> */
    private function futureLeave(int $employeeId, string $date): array
    {
        return DB::table('leave_requests as r')->join('leave_types as t', 't.id', '=', 'r.leave_type_id')->where('r.tenant_id', LeaveOps::tenantId())->where('r.employee_id', $employeeId)->whereIn('r.status', ['pending', 'approved'])
            ->where('r.end_date', '>=', $date)->orderBy('r.start_date')->get(['r.uuid', 'r.status', 'r.start_date', 'r.end_date', 'r.charged_units', 't.code as leave_type_code'])
            ->map(fn ($r) => ['uuid' => $r->uuid, 'status' => $r->status, 'leave_type' => $r->leave_type_code, 'start_date' => substr((string) $r->start_date, 0, 10), 'end_date' => substr((string) $r->end_date, 0, 10), 'units' => Units::normalize($r->charged_units)])->all();
    }

    /** @return array<string,mixed> merged transfer/encashment/separation/rehire rule blocks (policy rules win over leave type config) */
    private function rules($resolver, int $employeeId, int $typeId, string $date, object $type): array
    {
        $config = json_decode((string) ($type->config ?? 'null'), true) ?: [];
        $rules = [];
        if ($resolver) {
            $found = $this->safe(fn () => $resolver->rulesFor($employeeId, $typeId, $date));
            $rules = $found['rules'] ?? [];
        }
        $out = [];
        foreach (['transfer', 'encashment', 'separation', 'rehire'] as $k) {
            $out[$k] = ($rules[$k] ?? null) ?: ($config[$k] ?? []);
        }

        return $out;
    }

    /** @return array{version_id:?int,policy_id:?int,policy:?string,version:?int} */
    private function policy($resolver, int $employeeId, string $date): array
    {
        $r = $resolver ? $this->safe(fn () => $resolver->resolve($employeeId, $date)) : null;

        return ['version_id' => $r['version']->id ?? null, 'policy_id' => $r['policy']->id ?? null, 'policy' => $r['policy']->name ?? null, 'version' => $r['version']->version ?? null];
    }

    /** Organization Memory: where the person sat the day before and on the effective date (effective-dated, never "current"). @return array<string,mixed> */
    private function organization(int $employeeId, string $date): array
    {
        $employee = \App\Domain\People\Models\Employee::query()->find($employeeId);
        $memory = app(\App\Domain\People\Memory\OrganizationMemory::class);
        $pick = fn (?array $snap) => $snap && ($snap['assignment'] ?? null) ? ['names' => $snap['assignment']['names'] ?? [], 'ids' => $snap['assignment']['ids'] ?? []] : null;
        if (! $employee) {
            return ['before' => null, 'after' => null];
        }

        return ['before' => $this->safe(fn () => $pick($memory->snapshot($employee, $this->before($date)))), 'after' => $this->safe(fn () => $pick($memory->snapshot($employee, $date)))];
    }

    private function safe(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }

    private function before(string $date): string
    {
        return \Carbon\CarbonImmutable::parse($date)->subDay()->toDateString();
    }

    private function assertInput(string $trigger, string $date, ?string $mode): void
    {
        if (! in_array($trigger, self::TRIGGERS, true)) {
            throw ValidationException::withMessages(['trigger' => 'Choose one of: '.implode(', ', self::TRIGGERS).'.']);
        }
        if (LeaveOps::date($date) === null) {
            throw ValidationException::withMessages(['effective_date' => 'Use the format YYYY-MM-DD.']);
        }
        if ($mode !== null && ! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['balance_mode' => 'Choose one of: '.implode(', ', self::MODES).'.']);
        }
    }
}
