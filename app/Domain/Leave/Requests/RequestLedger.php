<?php

namespace App\Domain\Leave\Requests;

use App\Domain\Leave\Contracts\LeaveLockGuard;
use App\Domain\Leave\Duration\RuleSet;
use App\Domain\Leave\Expiry\ConsumptionPlanner;
use App\Domain\Leave\Ledger\BalanceService;
use App\Domain\Leave\Ledger\LedgerPosting;
use App\Domain\Leave\Ledger\LedgerRejected;
use App\Domain\Leave\Ledger\LedgerService;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestSegment;
use App\Domain\Leave\Support\Units;
use App\Domain\Tenancy\TenantClock;

/**
 * The ONLY place leave requests touch the ledger, and always through LedgerService:
 *   reserve (pending hold) -> commit (scheduled / consumed, with source-lot allocations) -> release (reject/withdraw/cancel).
 * One ledger line per charged segment, so partial cancellation reverses exactly the days that are cancelled.
 * Idempotency keys: req:{uuid}:seg:{segment id}:reserve | :commit | :release | :cancel
 */
final class RequestLedger
{
    public function __construct(private LedgerService $ledger, private BalanceService $balances, private LeaveLockGuard $locks) {}

    public static function key(LeaveRequest $r, LeaveRequestSegment $s, string $step): string
    {
        return 'req:'.$r->uuid.':seg:'.$s->id.':'.$step;
    }

    /** Holds the segment's units. `$enforce` false only for statutory leave and controlled modifications (checked separately). */
    public function reserve(LeaveRequest $r, LeaveRequestSegment $s, LeaveAccount $account, bool $enforce, ?int $actorId, array $trace): LeaveLedgerEntry
    {
        try {
            $result = $this->ledger->post(new LedgerPosting($account->id, 'reservation', 'pending', Units::neg(Units::abs($s->getRawOriginal('units'))), $s->leave_date, self::key($r, $s, 'reserve'),
                requestId: $r->id, segmentId: $s->id, policyVersionId: $r->policy_version_id, reason: 'Leave request '.$r->uuid, meta: $trace, createdBy: $actorId, enforceAvailable: $enforce));
        } catch (LedgerRejected $e) {
            throw new RequestRejected($e->getMessage(), ['errors' => [$e->getMessage()], 'reason' => $e->reason ?? 'ledger_rejected'], $e->getStatusCode());
        }
        $s->update(['account_id' => $account->id, 'ledger_entry_id' => $result->entry->id]);

        return $result->entry;
    }

    /** Converts the hold into usage on approval: `scheduled` for future days, `consumed` for days already taken. */
    public function commit(LeaveRequest $r, LeaveRequestSegment $s, ?int $actorId): ?LeaveLedgerEntry
    {
        $entry = $s->ledger_entry_id ? LeaveLedgerEntry::query()->find($s->ledger_entry_id) : null;
        if (! $entry || $entry->bucket !== 'pending') {
            return $entry;
        }
        $bucket = $s->leave_date <= TenantClock::today() ? 'consumed' : 'scheduled';
        $allocations = $this->allocations($entry->account_id, Units::abs($entry->getRawOriginal('units')), $s->leave_date, $r);
        try {
            $done = $this->ledger->commitReservation($entry, $bucket, $s->leave_date, self::key($r, $s, 'commit'), $s->id, $actorId, $allocations, ['request' => $r->uuid, 'segment_date' => $s->leave_date, 'sources' => $allocations]);
        } catch (LedgerRejected $e) {
            throw new RequestRejected($e->getMessage(), ['errors' => [$e->getMessage()], 'reason' => $e->reason ?? 'ledger_rejected'], $e->getStatusCode());
        }
        $s->update(['ledger_entry_id' => $done['consumption']->entry->id]);

        return $done['consumption']->entry;
    }

    /** Reverses whatever debit currently stands for the segment (hold, scheduled or consumed). Locked periods refuse (423). */
    public function release(LeaveRequest $r, LeaveRequestSegment $s, ?int $actorId, string $reason): ?LeaveLedgerEntry
    {
        $live = $this->liveDebit($s);
        if (! $live) {
            return null;
        }
        $this->locks->assertUnlocked($s->employee_id, $s->leave_date);
        try {
            return $this->ledger->reverse($live, self::key($r, $s, $live->bucket === 'pending' ? 'release' : 'cancel'), $reason, TenantClock::today(), $live->bucket === 'pending' ? 'reservation_release' : 'reversal', $actorId)->entry;
        } catch (LedgerRejected $e) {
            throw new RequestRejected($e->getMessage(), ['errors' => [$e->getMessage()], 'reason' => $e->reason ?? 'ledger_rejected'], $e->getStatusCode());
        }
    }

    /** The unreversed debit behind a segment, following a settlement (scheduled -> consumed) if the scheduler already moved it. */
    public function liveDebit(LeaveRequestSegment $s): ?LeaveLedgerEntry
    {
        $e = $s->ledger_entry_id ? LeaveLedgerEntry::query()->find($s->ledger_entry_id) : null;
        for ($i = 0; $e && $i < 5; $i++) {
            $next = LeaveLedgerEntry::query()->where('request_id', $e->request_id)->where('entry_type', 'consumption')->where('bucket', 'consumed')->where('source_entry_id', $e->id)->first();
            if (! $next) {
                break;
            }
            $e = $next;
        }

        return $e && ! LeaveLedgerEntry::query()->where('reverses_entry_id', $e->id)->exists() ? $e : null;
    }

    /** Source-to-use trace: which credit lots pay for this debit, in the policy's `consumption_order` (B's ConsumptionPlanner; default earliest-expiring first). @return array<int,array{source_entry_id:int,units:string}> */
    private function allocations(int $accountId, string $units, string $date, LeaveRequest $r): array
    {
        $order = RuleSet::load($r->employee_id, $r->leave_type_id, $date, $r->policy_version_id)->rules['consumption_order'] ?? null;

        return app(ConsumptionPlanner::class)->allocate($accountId, $units, $date, ConsumptionPlanner::normalizeOrder($order))['allocations'];
    }
}
