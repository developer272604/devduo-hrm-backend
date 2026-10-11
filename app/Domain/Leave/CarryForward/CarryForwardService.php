<?php

namespace App\Domain\Leave\CarryForward;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\Leave\Accrual\AccountService;
use App\Domain\Leave\Accrual\PeriodResolver;
use App\Domain\Leave\Accrual\RuleReader;
use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Expiry\ExpiryService;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveCarryForward;
use App\Domain\Leave\Models\LeaveType;
use App\Domain\Leave\Support\LeaveEvents;
use App\Domain\Leave\Support\LeaveSettings;
use App\Domain\Leave\Support\Units;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Period close: expire what is due, carry forward per policy, forfeit the rest, close the account.
 *
 * Policy (rules.carry_forward): mode none | full | capped | percentage; cap (units); percent (0-100); rounding (e.g. nearest_half);
 * valid_days / valid_months (how long carried units live in the new period). Plus limits.max_balance on the receiving account.
 * Ledger movements (all LedgerService, all with traces):
 *   old account  expiry/expired  -forfeited        (what the policy does not carry)
 *                carry_forward_out/carried -carried
 *   new account  carry_forward_in/carried +carried  (expires_on = valid_until)
 * An account with unsettled pending / scheduled units is skipped (and retried by the next run) so reservations never meet a closed account.
 */
final class CarryForwardService
{
    public function __construct(private LedgerService $ledger, private BalanceService $balances, private AccountService $accounts, private ExpiryService $expiry, private PeriodResolver $periods, private DomainEvents $events) {}

    /** @param array{as_of?:string,leave_type_ids?:array,employee_ids?:array} $params */
    public function baseKey(array $params): string
    {
        $asOf = $params['as_of'] ?? TenantClock::today();
        $types = collect($params['leave_type_ids'] ?? [])->map(fn ($v) => (int) $v)->sort()->values()->all();
        $emps = collect($params['employee_ids'] ?? [])->map(fn ($v) => (int) $v)->sort()->values()->all();

        return 'carry:'.$asOf.':'.substr(sha1(json_encode([$types, $emps])), 0, 10);
    }

    /** @return array{run_key:string,status:string,items:array<int,LeaveCarryForward>} */
    public function preview(array $params): array
    {
        return $this->handle($params, false);
    }

    public function run(array $params, ?User $user = null): array
    {
        return $this->handle($params, true, $user);
    }

    private function handle(array $params, bool $post, ?User $user = null): array
    {
        $asOf = $params['as_of'] ?? TenantClock::today();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) || ! CarbonImmutable::hasFormat($asOf, 'Y-m-d')) {
            throw ValidationException::withMessages(['as_of' => ['Use a date in Y-m-d format.']]);
        }
        $base = $this->baseKey($params + ['as_of' => $asOf]);
        $accounts = LeaveAccount::query()->where('status', 'open')->where('period_end', '<', $asOf)
            ->when($params['leave_type_ids'] ?? null, fn ($q, $ids) => $q->whereIn('leave_type_id', $ids))->when($params['employee_ids'] ?? null, fn ($q, $ids) => $q->whereIn('employee_id', $ids))
            ->orderBy('period_end')->orderBy('id')->get();
        $items = [];
        foreach ($accounts as $account) {
            $items[] = $this->account($account, $base.':'.substr((string) $account->getRawOriginal('period_end'), 0, 10), $post, $user);
        }
        if ($post) {
            Audit::record('leave_carry_forward_run', 'leave', 'leave_carry_forward:'.$base, ['accounts' => count($items), 'posted' => count(array_filter($items, fn ($i) => $i->status === 'posted'))]);
            $this->events->record(LeaveEvents::CARRY_FORWARD_COMPLETED, ['summary' => 'Leave carry-forward run', 'actor_id' => $user?->id, 'effective_date' => $asOf,
                'payload' => ['run_key' => $base, 'accounts' => count($items), 'posted' => count(array_filter($items, fn ($i) => $i->status === 'posted'))]]);
        }

        return ['run_key' => $base, 'status' => $post ? 'completed' : 'preview', 'items' => $items];
    }

    private function account(LeaveAccount $account, string $key, bool $post, ?User $user): LeaveCarryForward
    {
        $row = LeaveCarryForward::query()->where('run_key', $key)->where('employee_id', $account->employee_id)->where('leave_type_id', $account->leave_type_id)->first();
        if ($row && $row->status === 'posted') {
            return $row;
        }
        $end = substr((string) $account->getRawOriginal('period_end'), 0, 10);
        $values = ['from_account_id' => $account->id, 'mode' => 'none', 'eligible_units' => '0', 'carried_units' => '0', 'forfeited_units' => '0', 'valid_until' => null, 'status' => 'preview', 'calculation' => null];
        try {
            $type = LeaveType::query()->find($account->leave_type_id);
            $resolved = app(LeavePolicyResolver::class)->rulesFor($account->employee_id, $account->leave_type_id, $end);
            if (! $type?->tracks_balance || ! $resolved) {
                return $this->store($row, $account, $key, $values + [], 'skipped', ['reason' => ! $resolved ? 'type_not_granted' : 'balance_not_tracked']);
            }
            $rule = RuleReader::read($resolved);
            if ($post) {
                $this->expiry->process(CarbonImmutable::parse($end)->addDay()->toDateString(), [$account->employee_id]);
            }
            $plan = $this->plan($account, $rule, $end, ! $post);
            $values = array_merge($values, ['mode' => $plan['mode'], 'eligible_units' => $plan['eligible'], 'carried_units' => $plan['carried'], 'forfeited_units' => $plan['forfeited'], 'valid_until' => $plan['valid_until']]);
            if ($plan['blocked']) {
                return $this->store($row, $account, $key, $values, 'skipped', $plan['calculation']);
            }
            if (! $post) {
                return $this->store($row, $account, $key, $values, 'preview', $plan['calculation']);
            }

            return DB::transaction(function () use ($account, $key, $row, $values, $plan, $end, $user) {
                $meta = ['carry_run' => $key, 'system' => true];
                $forfeitEntry = null;
                $out = $in = $to = null;
                if (! Units::isZero($plan['forfeited'])) {
                    $forfeitEntry = $this->ledger->post(new LedgerPosting($account->id, 'expiry', 'expired', Units::neg($plan['forfeited']), $end, $key.':forfeit:'.$account->employee_id.':'.$account->leave_type_id,
                        reason: 'Unused leave not carried forward', meta: $meta + ['mode' => $plan['mode']], createdBy: $user?->id, ignoreLock: true, allowClosed: true))->entry;
                }
                if (! Units::isZero($plan['carried'])) {
                    $next = CarbonImmutable::parse($end)->addDay()->toDateString();
                    $to = $this->accounts->ensure($account->employee_id, $account->leave_type_id, $next);
                    $out = $this->ledger->post(new LedgerPosting($account->id, 'carry_forward_out', 'carried', Units::neg($plan['carried']), $end, $key.':out:'.$account->employee_id.':'.$account->leave_type_id,
                        reason: 'Carried forward to '.$to->period_start, meta: $meta + ['to_account' => $to->uuid], createdBy: $user?->id, ignoreLock: true, allowClosed: true))->entry;
                    $in = $this->ledger->post(new LedgerPosting($to->id, 'carry_forward_in', 'carried', $plan['carried'], substr((string) $to->getRawOriginal('period_start'), 0, 10), $key.':in:'.$account->employee_id.':'.$account->leave_type_id,
                        expiresOn: $plan['valid_until'], sourceEntryId: $out->id, reason: 'Carried forward from '.$account->period_start, meta: $meta + ['from_account' => $account->uuid], createdBy: $user?->id, ignoreLock: true))->entry;
                }
                $account->forceFill(['status' => 'closed'])->save();
                $values += ['to_account_id' => $to?->id, 'out_entry_id' => $out?->id, 'in_entry_id' => $in?->id];

                return $this->store($row, $account, $key, $values, 'posted', $plan['calculation'] + ['forfeit_entry_id' => $forfeitEntry?->id]);
            });
        } catch (\Throwable $e) {
            if (! ($e instanceof HttpException)) {
                report($e);
            }

            return $this->store($row, $account, $key, $values, 'failed', ['error' => $e instanceof HttpException ? $e->getMessage() : 'Unexpected error: '.class_basename($e)]);
        }
    }

    /** @return array<string,mixed> */
    private function plan(LeaveAccount $account, array $rule, string $end, bool $subtractExpiring): array
    {
        $carry = $rule['carry'];
        $mode = in_array($carry['mode'] ?? 'none', ['none', 'full', 'capped', 'percentage'], true) ? ($carry['mode'] ?? 'none') : 'none';
        $b = $this->balances->balance($account, $end);
        $calc = ['available' => $b->available, 'pending' => $b->pending, 'scheduled' => $b->scheduled, 'carry_rule' => $carry];
        if (! Units::isZero($b->pending) || ! Units::isZero($b->scheduled)) {
            return ['mode' => $mode, 'eligible' => '0.0000', 'carried' => '0.0000', 'forfeited' => '0.0000', 'valid_until' => null, 'blocked' => true, 'calculation' => $calc + ['reason' => 'open_reservations']];
        }
        if (Units::isNegative($b->available)) {
            $debt = LeaveSettings::get('balance.carry_negative_forward', true) ? $b->available : '0.0000';
            $calc += ['negative_balance' => $b->available, 'negative_carry_forward_enabled' => (bool) LeaveSettings::get('balance.carry_negative_forward', true)];

            return ['mode' => $mode, 'eligible' => $debt, 'carried' => $debt, 'forfeited' => '0.0000', 'valid_until' => null, 'blocked' => false,
                'calculation' => $calc + ['eligible' => $debt, 'carried' => $debt, 'note' => Units::isZero($debt) ? 'negative_balance_not_carried_by_setting' : 'negative_balance_carried']];
        }
        $expiring = '0.0000';
        if ($subtractExpiring) {
            foreach ($this->balances->lots($account, $end) as $lot) {
                if ($lot['expires_on'] !== null && $lot['expires_on'] <= $end) {
                    $expiring = Units::add($expiring, $lot['remaining']);
                }
            }
        }
        $available = Units::max('0', $b->available);
        $eligible = Units::max('0', Units::sub($available, Units::min($expiring, $available)));
        $carried = match ($mode) {
            'full' => $eligible,
            'capped' => Units::min($eligible, Units::normalize($carry['cap'] ?? 0)),
            'percentage' => RuleReader::roundUnits(Units::round(bcmul($eligible, bcdiv((string) min(100, max(0, (float) ($carry['percent'] ?? 0))), '100', 8), 12)), (string) ($carry['rounding'] ?? 'none')),
            default => '0.0000',
        };
        if ($mode === 'percentage' && isset($carry['cap'])) {
            $carried = Units::min($carried, Units::normalize($carry['cap']));
        }
        $next = CarbonImmutable::parse($end)->addDay()->toDateString();
        if ($rule['max_balance'] !== null && Units::cmp($carried, '0') > 0) {
            $joined = Employee::query()->whereKey($account->employee_id)->value('joined_on');
            $np = $this->periods->forDate($joined ? substr((string) $joined, 0, 10) : null, $rule['cycle'], $next);
            $existing = $this->accounts->find($account->employee_id, $account->leave_type_id, $np['start']);
            $room = Units::max('0', Units::sub($rule['max_balance'], $existing ? $this->balances->balance($existing, $np['start'])->available : '0'));
            if (Units::cmp($carried, $room) > 0) {
                $calc['capped_by_max_balance'] = $rule['max_balance'];
                $carried = $room;
            }
        }
        $validUntil = null;
        if (! empty($carry['valid_days'])) {
            $validUntil = CarbonImmutable::parse($next)->addDays((int) $carry['valid_days'] - 1)->toDateString();
        } elseif (! empty($carry['valid_months'])) {
            $validUntil = CarbonImmutable::parse($next)->addMonthsNoOverflow((int) $carry['valid_months'])->subDay()->toDateString();
        }

        return ['mode' => $mode, 'eligible' => $eligible, 'carried' => $carried, 'forfeited' => Units::sub($eligible, $carried), 'valid_until' => $validUntil, 'blocked' => false,
            'calculation' => $calc + ['expiring_before_close' => $expiring, 'eligible' => $eligible, 'carried' => $carried]];
    }

    private function store(?LeaveCarryForward $row, LeaveAccount $account, string $key, array $values, string $status, array $calc): LeaveCarryForward
    {
        $values['status'] = $status;
        $values['calculation'] = $calc;
        if ($row) {
            $row->forceFill($values)->save();

            return $row;
        }

        return LeaveCarryForward::create(['run_key' => $key, 'employee_id' => $account->employee_id, 'leave_type_id' => $account->leave_type_id, 'from_account_id' => $account->id] + $values);
    }
}
