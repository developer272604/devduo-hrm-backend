<?php

namespace App\Domain\Leave\Operations\Quality;

use App\Domain\Access\Classification;
use App\Domain\Attendance\Operations\Ops;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Operations\LeaveOps;
use App\Domain\Leave\Support\Units;
use App\Domain\Quality\Finding;
use App\Domain\Quality\QualityRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Leave data-quality rules (QualityRule registry). READ-ONLY: they describe a problem and where to fix it, never change a balance,
 * request or policy. Neutral wording. Employee-level findings are manager_sensitive; configuration findings are hr_confidential.
 */
final class LeaveQualityRules
{
    /** @return array<int,QualityRule> */
    public static function all(): array
    {
        $r = new self;

        return [
            self::rule('leave_ledger_mismatch', 'Leave ledger total does not match its recomputed value', fn (int $t) => $r->ledgerMismatch($t)),
            self::rule('leave_negative_beyond_limit', 'Leave balance below its allowed minimum', fn (int $t) => $r->belowLimit($t)),
            self::rule('leave_no_policy', 'Employees without a leave policy', fn (int $t) => $r->noPolicy($t)),
            self::rule('leave_stale_pending_request', 'Leave requests pending for more than 14 days', fn (int $t) => $r->stalePending($t)),
            self::rule('leave_expired_lot_open', 'Expired leave not yet written off', fn (int $t) => $r->expiredOpen($t)),
            self::rule('leave_separated_with_balance', 'Separated employees with a leave balance and no settlement record', fn (int $t) => $r->separatedWithBalance($t)),
            self::rule('leave_unlock_waiting', 'Leave unlock requests waiting for more than 3 days', fn (int $t) => $r->unlockWaiting($t)),
        ];
    }

    private static function rule(string $key, string $label, \Closure $scan): QualityRule
    {
        return new class($key, $label, $scan) implements QualityRule
        {
            public function __construct(private string $key, private string $label, private \Closure $scanner) {}

            public function key(): string
            {
                return $this->key;
            }

            public function category(): string
            {
                return 'leave';
            }

            public function label(): string
            {
                return $this->label;
            }

            public function scan(int $tenantId): iterable
            {
                return ($this->scanner)($tenantId);
            }
        };
    }

    /** @return \Generator<int,LeaveAccount> open accounts of the tenant, 300 at a time */
    private function accounts(int $tenantId): \Generator
    {
        $last = 0;
        while (true) {
            $rows = LeaveAccount::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('status', 'open')->where('id', '>', $last)->orderBy('id')->limit(300)->get();
            if ($rows->isEmpty()) {
                return;
            }
            foreach ($rows as $row) {
                yield $row;
            }
            $last = $rows->last()->id;
        }
    }

    public function ledgerMismatch(int $tenantId): iterable
    {
        $balances = app(BalanceService::class);
        foreach ($this->accounts($tenantId) as $a) {
            $v = $balances->verify($a);
            if (! $v['consistent']) {
                yield new Finding((int) $a->employee_id, 'acct:'.$a->id, 'Leave ledger does not reconcile', 'The ledger sum for one leave account differs from its recomputed value.', 'critical', ['leave_account', $a->id], ['exact' => $v['exact'], 'database' => $v['database']], Classification::HR_CONFIDENTIAL);
            }
        }
    }

    public function belowLimit(int $tenantId): iterable
    {
        $balances = app(BalanceService::class);
        $today = Ops::today();
        foreach ($this->accounts($tenantId) as $a) {
            $available = $balances->balance($a, $today)->available;
            $limit = Units::normalize($a->getRawOriginal('negative_limit'));
            if (Units::cmp($available, Units::neg($limit)) < 0) {
                yield new Finding((int) $a->employee_id, 'acct:'.$a->id, 'Leave balance is below its allowed minimum', 'Available '.$available.' is below -'.$limit.'. Review the adjustments and requests behind it.', 'action_required', ['leave_account', $a->id], ['available' => $available, 'negative_limit' => $limit], Classification::MANAGER_SENSITIVE);
            }
        }
    }

    public function noPolicy(int $tenantId): iterable
    {
        $resolver = LeaveOps::resolver();
        if (! $resolver) {
            return;
        }
        $today = Ops::today();
        $last = 0;
        while (true) {
            $rows = DB::table('employees')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('status', Ops::WORKING)->where('id', '>', $last)->orderBy('id')->limit(300)->get(['id']);
            if ($rows->isEmpty()) {
                return;
            }
            foreach ($rows as $row) {
                try {
                    $found = $resolver->resolve((int) $row->id, $today);
                } catch (\Throwable) {
                    continue;
                }
                if (! $found) {
                    yield new Finding((int) $row->id, 'emp:'.$row->id, 'No leave policy applies', 'No leave policy assignment covers this employee today. Assign a policy so leave can be requested.', 'warning', ['employee', (int) $row->id], [], Classification::MANAGER_SENSITIVE);
                }
            }
            $last = $rows->last()->id;
        }
    }

    public function stalePending(int $tenantId): iterable
    {
        $cut = CarbonImmutable::parse(Ops::today())->subDays(14)->toDateString();
        foreach (DB::table('leave_requests')->where('tenant_id', $tenantId)->where('status', 'pending')->where(DB::raw('coalesce(submitted_at, created_at)'), '<', $cut)->orderBy('id')->cursor() as $r) {
            yield new Finding((int) $r->employee_id, 'req:'.$r->id, 'Leave request pending for more than 14 days', 'The request starting '.substr((string) $r->start_date, 0, 10).' has no decision yet.', 'warning', ['leave_request', (int) $r->id], [], Classification::MANAGER_SENSITIVE);
        }
    }

    public function expiredOpen(int $tenantId): iterable
    {
        $balances = app(BalanceService::class);
        $today = Ops::today();
        foreach ($this->accounts($tenantId) as $a) {
            foreach ($balances->lots($a, $today) as $lot) {
                if ($lot['expires_on'] !== null && $lot['expires_on'] < $today) {
                    yield new Finding((int) $a->employee_id, 'lot:'.$lot['entry_id'], 'Expired leave has not been written off', $lot['remaining'].' units expired on '.$lot['expires_on'].' but are still in the balance. Run the expiry process.', 'warning', ['leave_ledger_entry', $lot['entry_id']], ['remaining' => $lot['remaining'], 'expires_on' => $lot['expires_on']], Classification::MANAGER_SENSITIVE);
                }
            }
        }
    }

    public function separatedWithBalance(int $tenantId): iterable
    {
        $balances = app(BalanceService::class);
        $today = Ops::today();
        $handled = DB::table('leave_transfers')->where('tenant_id', $tenantId)->where('trigger', 'separation')->where('status', 'applied')->pluck('employee_id')->flip();
        foreach ($this->accounts($tenantId) as $a) {
            $e = DB::table('employees')->where('id', $a->employee_id)->first(['status', 'separated_on']);
            if (! $e || $e->status !== 'separated' || $handled->has((int) $a->employee_id)) {
                continue;
            }
            $available = $balances->balance($a, $today)->available;
            if (Units::cmp($available, '0') !== 0) {
                yield new Finding((int) $a->employee_id, 'acct:'.$a->id, 'Separated employee still holds a leave balance', 'Available '.$available.' with no applied settlement. Review it under Leave > Transfers.', 'action_required', ['leave_account', $a->id], ['available' => $available], Classification::HR_CONFIDENTIAL);
            }
        }
    }

    public function unlockWaiting(int $tenantId): iterable
    {
        $cut = now()->subDays(3);
        foreach (DB::table('leave_locks')->where('tenant_id', $tenantId)->where('status', 'locked')->whereNotNull('unlock_requested_by')->where('updated_at', '<', $cut)->get(['id', 'start_date', 'end_date']) as $l) {
            yield new Finding(null, 'lock:'.$l->id, 'Leave unlock request is waiting', 'An unlock request for '.substr((string) $l->start_date, 0, 10).' to '.substr((string) $l->end_date, 0, 10).' has waited more than 3 days.', 'warning', ['leave_lock', (int) $l->id], [], Classification::HR_CONFIDENTIAL);
        }
    }
}
