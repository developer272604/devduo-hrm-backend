<?php

namespace App\Domain\Leave\Explainer;

use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Support\Units;
use Illuminate\Support\Facades\DB;

/**
 * Leave Explainer: every number is derived from the ledger and the policy, never invented. Read-only.
 *
 *   balance()  -> per account: the balance, each bucket with the ledger entries behind it (plain-language sentences),
 *                 the credit lots (what expires when), the integrity check, the policy rules in force and the entitlement trace.
 *   request()  -> why a request was charged what it was (segments with explanations), what the ledger holds for it, who decided.
 */
final class LeaveExplainer
{
    public function __construct(private BalanceService $balances) {}

    /** @return array<string,mixed> */
    public function balance(object $employee, ?int $leaveTypeId = null, ?string $asOf = null, ?string $wantUnits = null): array
    {
        $asOf ??= LeaveOps::today();
        $accounts = [];
        foreach ($this->balances->forEmployee((int) $employee->id, $asOf, $leaveTypeId, false) as $row) {
            /** @var LeaveAccount $account */
            $account = $row['account'];
            $balance = $row['balance'];
            $type = DB::table('leave_types')->where('id', $account->leave_type_id)->first(['id', 'uuid', 'code', 'name', 'unit', 'is_statutory']);
            $statement = $this->balances->statement($account);
            $lines = array_map(fn ($s) => $s + ['sentence' => $this->sentence($s)], $statement);
            $buckets = [];
            foreach ($balance->trace as $bucket => $ids) {
                $buckets[$bucket] = ['units' => $balance->toArray()[$bucket === 'consumed' ? 'consumed' : $bucket] ?? null, 'entries' => array_values($ids)];
            }
            $policy = $this->policy((int) $employee->id, (int) $account->leave_type_id, $asOf);
            $entitlement = null;
            if (($engine = LeaveOps::engine()) && $account->status === 'open') {
                try {
                    $entitlement = $engine->entitlementFor((int) $employee->id, (int) $account->leave_type_id, substr((string) $account->getRawOriginal('period_start'), 0, 10), substr((string) $account->getRawOriginal('period_end'), 0, 10));
                } catch (\Throwable) {
                    $entitlement = null;
                }
            }
            $negative = Units::normalize($account->getRawOriginal('negative_limit'));
            $check = null;
            if ($wantUnits !== null) {
                $after = Units::sub($balance->available, $wantUnits);
                $ok = Units::cmp($after, Units::neg($negative)) >= 0;
                $check = ['requested' => Units::normalize($wantUnits), 'available' => $balance->available, 'negative_limit' => $negative, 'would_remain' => $after, 'allowed' => $ok,
                    'sentence' => $ok ? 'Taking '.Units::normalize($wantUnits).' would leave '.$after.' (the lowest allowed is -'.$negative.').' : 'Taking '.Units::normalize($wantUnits).' would leave '.$after.', below the lowest allowed balance of -'.$negative.', so the ledger would refuse it.'];
            }
            $accounts[] = ['account_uuid' => $account->uuid, 'leave_type' => ['uuid' => $type->uuid, 'code' => $type->code, 'name' => $type->name, 'unit' => $type->unit, 'statutory' => (bool) $type->is_statutory],
                'period' => [substr((string) $account->getRawOriginal('period_start'), 0, 10), substr((string) $account->getRawOriginal('period_end'), 0, 10)], 'status' => $account->status, 'negative_limit' => $negative,
                'balance' => collect($balance->toArray())->except('trace', 'accountId')->all(), 'buckets' => $buckets, 'ledger' => $lines, 'lots' => $this->balances->lots($account, $asOf), 'integrity' => $this->balances->verify($account),
                'policy' => $policy, 'entitlement' => $entitlement, 'check' => $check,
                'summary' => 'Entitled '.$balance->entitled.' (opening '.$balance->opening.' + accrued '.$balance->accrued.' + carried '.$balance->carried.' + adjusted '.$balance->adjusted.'), used '.$balance->consumed.', pending '.$balance->pending.', scheduled '.$balance->scheduled.', expired '.$balance->expired.', encashed '.$balance->encashed.'. Available '.$balance->available.'.'];
        }

        return ['employee_uuid' => $employee->uuid, 'as_of' => $asOf, 'accounts' => $accounts, 'engine_available' => LeaveOps::engine() !== null, 'policy_resolver_available' => LeaveOps::resolver() !== null];
    }

    /** @return array<string,mixed>|null */
    public function request(string $requestUuid): ?array
    {
        $r = DB::table('leave_requests as r')->join('leave_types as t', 't.id', '=', 'r.leave_type_id')->where('r.tenant_id', LeaveOps::tenantId())->where('r.uuid', $requestUuid)
            ->first(['r.id', 'r.uuid', 'r.employee_id', 'r.status', 'r.start_date', 'r.end_date', 'r.unit', 'r.portion', 'r.requested_units', 'r.charged_units', 'r.policy_version_id', 'r.explanation', 'r.confidence', 'r.warnings', 'r.is_sensitive', 't.code', 't.name']);
        if (! $r) {
            return null;
        }
        $segments = DB::table('leave_request_segments')->where('request_id', $r->id)->orderBy('leave_date')->get(['leave_date', 'portion', 'minutes', 'units', 'pay_class', 'day_kind', 'charged', 'status', 'explanation']);
        $entries = DB::table('leave_ledger_entries')->where('request_id', $r->id)->orderBy('id')->get(['uuid', 'entry_type', 'bucket', 'units', 'effective_date', 'reverses_entry_id']);
        $actions = DB::table('leave_request_actions')->where('request_id', $r->id)->orderBy('id')->get();
        $segs = $segments->map(fn ($s) => ['date' => substr((string) $s->leave_date, 0, 10), 'portion' => $s->portion, 'minutes' => (int) $s->minutes, 'units' => Units::normalize($s->units), 'pay_class' => $s->pay_class, 'day_kind' => $s->day_kind,
            'charged' => (bool) $s->charged, 'status' => $s->status, 'why' => $this->why($s), 'explanation' => json_decode($s->explanation ?? 'null', true)])->all();
        $charged = Units::sum(array_map(fn ($s) => $s['charged'] && $s['status'] === 'active' ? $s['units'] : '0', $segs));

        return ['request_uuid' => $r->uuid, 'leave_type' => $r->code, 'status' => $r->status, 'dates' => [substr((string) $r->start_date, 0, 10), substr((string) $r->end_date, 0, 10)], 'requested_units' => Units::normalize($r->requested_units),
            'charged_units' => Units::normalize($r->charged_units), 'segments_total' => $charged, 'matches' => Units::cmp($charged, $r->charged_units) === 0 || $segs === [], 'policy_version_id' => $r->policy_version_id, 'confidence' => $r->confidence,
            'segments' => $segs, 'ledger' => $entries->map(fn ($e) => ['entry' => $e->uuid, 'entry_type' => $e->entry_type, 'bucket' => $e->bucket, 'units' => Units::normalize($e->units), 'effective_date' => substr((string) $e->effective_date, 0, 10), 'is_reversal' => $e->reverses_entry_id !== null])->all(),
            'timeline' => $actions->map(fn ($a) => ['action' => $a->action ?? null, 'at' => LeaveOps::iso($a->created_at ?? null)])->all(), 'sensitive' => (bool) $r->is_sensitive,
            'sentence' => 'Requested '.Units::normalize($r->requested_units).' and charged '.Units::normalize($r->charged_units).' ('.$r->code.', '.substr((string) $r->start_date, 0, 10).' to '.substr((string) $r->end_date, 0, 10).'); '.count(array_filter($segs, fn ($s) => ! $s['charged'])).' day(s) were not charged.'];
    }

    private function why(object $s): string
    {
        $date = substr((string) $s->leave_date, 0, 10);
        if (! $s->charged) {
            return $date.' was not charged because it is a '.str_replace('_', ' ', $s->day_kind).'.';
        }

        return $date.' was charged '.Units::normalize($s->units).' ('.str_replace('_', ' ', $s->portion).', '.str_replace('_', ' ', $s->pay_class).').';
    }

    private function sentence(array $s): string
    {
        $abs = Units::abs($s['units']);
        $sign = Units::isNegative($s['units']) ? 'took away' : 'added';
        $what = match ($s['entry_type']) {
            'opening' => 'Opening balance of '.$abs, 'accrual' => 'Accrual of '.$abs, 'reservation' => 'Held '.$abs.' for a pending request', 'reservation_release' => 'Released a hold of '.$abs,
            'consumption' => 'Leave taken: '.$abs, 'settlement' => 'Scheduled leave of '.$abs.' became taken', 'reversal' => 'Reversal of '.$abs, 'adjustment' => 'Manual adjustment of '.$abs,
            'carry_forward_out' => 'Carried out '.$abs.' at period end', 'carry_forward_in' => 'Carried in '.$abs, 'expiry' => $abs.' expired', 'comp_off_credit' => 'Comp-off credit of '.$abs,
            'encashment' => $abs.' set aside for encashment', 'transfer_out' => $abs.' moved out', 'transfer_in' => $abs.' moved in', 'donation_out' => 'Donated '.$abs, 'donation_in' => 'Received donation of '.$abs,
            default => ucfirst(str_replace('_', ' ', $s['entry_type'])).' of '.$abs,
        };

        return $what.' on '.$s['effective_date'].' ('.$sign.' '.$abs.'; available is now '.$s['running_available'].').'.($s['reason'] ? ' Reason: '.$s['reason'] : '');
    }

    /** @return array<string,mixed>|null */
    private function policy(int $employeeId, int $typeId, string $date): ?array
    {
        $resolver = LeaveOps::resolver();
        if (! $resolver) {
            return null;
        }
        try {
            $found = $resolver->rulesFor($employeeId, $typeId, $date);

            return $found ? ['version_id' => $found['version_id'], 'policy_id' => $found['policy_id'], 'reason' => $found['reason'] ?? null, 'rules' => $found['rules']] : ['granted' => false];
        } catch (\Throwable) {
            return null;
        }
    }
}
