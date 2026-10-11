<?php

namespace App\Domain\Leave\Ledger;

use App\Domain\Leave\Contracts\LeaveLockGuard;
use App\Domain\Leave\Models\LeaveAccount;
use App\Domain\Leave\Models\LeaveLedgerAllocation;
use App\Domain\Leave\Models\LeaveLedgerEntry;
use App\Domain\Leave\Support\Units;
use App\Domain\Tenancy\TenantClock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONLY writer of leave_ledger_entries.
 *
 *   - Append-only: no update/delete; corrections are reversal or adjustment rows.
 *   - Idempotent: the same idempotency key returns the original entry (PostResult::$duplicate) and never writes twice; reusing
 *     a key for a different posting is a 409.
 *   - Concurrency-safe: every post runs in a transaction that takes a row lock (SELECT ... FOR UPDATE) on the leave account,
 *     recomputes `available` under that lock and only then inserts, so two simultaneous reservations cannot both succeed
 *     against the same balance. Unique indexes (idempotency key, one reversal per entry) are the backstop.
 *   - Lock-aware: postings dated inside a locked period are refused (423) unless the posting is a system posting with ignoreLock.
 *   - Decimal-safe: units are 4-dp strings handled with bcmath (App\Domain\Leave\Support\Units).
 */
final class LedgerService
{
    public function __construct(private BalanceService $balances, private LeaveLockGuard $locks) {}

    public function post(LedgerPosting $p): PostResult
    {
        $this->validate($p);
        try {
            return DB::transaction(fn () => $this->postLocked($p));
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race on the unique indexes: whoever won already wrote the row.
            if ($existing = $this->find($p->idempotencyKey)) {
                return $this->asDuplicate($existing, $p);
            }
            if ($p->reversesEntryId) {
                throw LedgerRejected::alreadyReversed();
            }
            throw $e;
        }
    }

    /** Hold units for a pending request (bucket pending). `$units` is a positive magnitude. Refuses to overdraw. */
    public function reserve(int $accountId, string $units, string $idempotencyKey, string $effectiveDate, ?int $requestId = null, ?string $reason = null, ?int $createdBy = null, ?int $policyVersionId = null, ?array $meta = null): PostResult
    {
        return $this->post(new LedgerPosting($accountId, 'reservation', 'pending', Units::neg(Units::abs($units)), $effectiveDate, $idempotencyKey, requestId: $requestId, policyVersionId: $policyVersionId,
            reason: $reason, meta: $meta, createdBy: $createdBy, enforceAvailable: true));
    }

    /**
     * Turn a reservation into usage: releases the hold (reversal of the reservation) and posts the consumption in the same
     * transaction. `$bucket` is 'consumed' (leave already taken) or 'scheduled' (approved, in the future).
     *
     * @param  array<int,array{source_entry_id:int,units:string}>  $allocations  credit lots drawn on (source-to-use trace)
     * @return array{release:PostResult,consumption:PostResult}
     */
    public function commitReservation(LeaveLedgerEntry|int $reservation, string $bucket, string $effectiveDate, string $idempotencyKey, ?int $segmentId = null, ?int $createdBy = null, array $allocations = [], ?array $meta = null): array
    {
        if (! in_array($bucket, ['consumed', 'scheduled'], true)) {
            throw new InvalidArgumentException('Bucket must be consumed or scheduled.');
        }
        $reservation = $this->entry($reservation);

        return DB::transaction(function () use ($reservation, $bucket, $effectiveDate, $idempotencyKey, $segmentId, $createdBy, $allocations, $meta) {
            $release = $this->reverse($reservation, $idempotencyKey.':release', 'Reservation converted to usage', $effectiveDate, 'reservation_release', $createdBy);
            $consumption = $this->post(new LedgerPosting($reservation->account_id, 'consumption', $bucket, Units::neg(Units::abs($reservation->getRawOriginal('units'))), $effectiveDate, $idempotencyKey.':use',
                requestId: $reservation->request_id, segmentId: $segmentId, policyVersionId: $reservation->policy_version_id, sourceEntryId: $reservation->id, meta: $meta, createdBy: $createdBy, allocations: $allocations));

            return ['release' => $release, 'consumption' => $consumption];
        });
    }

    /** Move an approved (scheduled) debit to consumed once the leave date has passed: two new rows, the original stays untouched. @return array{release:PostResult,consumption:PostResult} */
    public function settleScheduled(LeaveLedgerEntry|int $scheduled, string $effectiveDate, string $idempotencyKey, ?int $createdBy = null): array
    {
        $scheduled = $this->entry($scheduled);
        if ($scheduled->bucket !== 'scheduled' || ! Units::isNegative($scheduled->getRawOriginal('units'))) {
            throw LedgerRejected::invalid('Only a scheduled debit can be settled.');
        }
        $magnitude = Units::abs($scheduled->getRawOriginal('units'));

        return DB::transaction(fn () => [
            'release' => $this->post(new LedgerPosting($scheduled->account_id, 'settlement', 'scheduled', $magnitude, $effectiveDate, $idempotencyKey.':release', requestId: $scheduled->request_id, sourceEntryId: $scheduled->id, createdBy: $createdBy)),
            'consumption' => $this->post(new LedgerPosting($scheduled->account_id, 'consumption', 'consumed', Units::neg($magnitude), $effectiveDate, $idempotencyKey.':use', requestId: $scheduled->request_id, sourceEntryId: $scheduled->id, createdBy: $createdBy)),
        ]);
    }

    /**
     * Post the exact opposite of an entry (same bucket, opposite sign) linked by reverses_entry_id. Each entry can be reversed
     * once; a reversal cannot be reversed. Reversing a credit is refused if it would overdraw the account.
     */
    public function reverse(LeaveLedgerEntry|int $entry, string $idempotencyKey, ?string $reason = null, ?string $effectiveDate = null, string $entryType = 'reversal', ?int $createdBy = null, bool $ignoreLock = false): PostResult
    {
        $entry = $this->entry($entry);
        if ($existing = $this->find($idempotencyKey)) {
            return $this->asDuplicate($existing, null, $entry->account_id);
        }
        $units = Units::neg($entry->getRawOriginal('units'));

        return $this->post(new LedgerPosting($entry->account_id, $entryType, $entry->bucket, $units, $effectiveDate ?? TenantClock::today(), $idempotencyKey, requestId: $entry->request_id,
            segmentId: $entry->segment_id, policyVersionId: $entry->policy_version_id, sourceEntryId: $entry->id, reason: $reason ?? 'Reversal of entry '.$entry->uuid, createdBy: $createdBy,
            enforceAvailable: Units::isNegative($units), ignoreLock: $ignoreLock, allowClosed: true, reversesEntryId: $entry->id));
    }

    public function find(string $idempotencyKey): ?LeaveLedgerEntry
    {
        return LeaveLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    private function entry(LeaveLedgerEntry|int $entry): LeaveLedgerEntry
    {
        return $entry instanceof LeaveLedgerEntry ? $entry : LeaveLedgerEntry::query()->findOrFail($entry);
    }

    private function validate(LedgerPosting $p): void
    {
        if (! in_array($p->entryType, LeaveLedgerEntry::TYPES, true)) {
            throw LedgerRejected::invalid("Unknown ledger entry type \"$p->entryType\".");
        }
        if (! in_array($p->bucket, LeaveLedgerEntry::BUCKETS, true)) {
            throw LedgerRejected::invalid("Unknown ledger bucket \"$p->bucket\".");
        }
        if ($p->idempotencyKey === '' || strlen($p->idempotencyKey) > 120) {
            throw LedgerRejected::invalid('An idempotency key of 1-120 characters is required.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $p->effectiveDate) || ($p->expiresOn !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $p->expiresOn))) {
            throw LedgerRejected::invalid('Dates must be Y-m-d.');
        }
        try {
            $units = Units::strict($p->units);
        } catch (InvalidArgumentException $e) {
            throw LedgerRejected::invalid($e->getMessage());
        }
        if (Units::isZero($units)) {
            throw LedgerRejected::invalid('A ledger entry cannot be zero.');
        }
        if ($p->allocations && ! Units::isNegative($units)) {
            throw LedgerRejected::invalid('Only a debit can draw on credit lots.');
        }
    }

    private function postLocked(LedgerPosting $p): PostResult
    {
        $units = Units::strict($p->units);
        // Row lock: serializes every posting against this account (no-op on SQLite, whose single writer already serializes).
        $account = LeaveAccount::query()->whereKey($p->accountId)->lockForUpdate()->firstOrFail();

        if ($existing = $this->find($p->idempotencyKey)) {
            return $this->asDuplicate($existing, $p);
        }
        if ($account->status !== 'open' && ! $p->allowClosed) {
            throw LedgerRejected::accountClosed();
        }
        if (! $p->ignoreLock) {
            $this->locks->assertUnlocked($account->employee_id, $p->effectiveDate);
        }
        $skipOverdraw = false;
        if ($p->reversesEntryId) {
            $original = LeaveLedgerEntry::query()->findOrFail($p->reversesEntryId);
            if ((int) $original->account_id !== (int) $account->id) {
                throw LedgerRejected::invalid('A reversal must be posted to the same account as the entry it reverses.');
            }
            if ($original->reverses_entry_id) {
                throw LedgerRejected::notReversible();
            }
            if (LeaveLedgerEntry::query()->where('reverses_entry_id', $original->id)->exists()) {
                throw LedgerRejected::alreadyReversed();
            }
            // Cancelling a grant that has not taken effect yet cannot overdraw anything.
            $skipOverdraw = substr((string) $original->getRawOriginal('effective_date'), 0, 10) > $p->effectiveDate && in_array($original->bucket, BalanceService::CREDIT_BUCKETS, true);
        }
        if ($p->enforceAvailable && ! $skipOverdraw && Units::isNegative($units)) {
            $available = $this->balances->balance($account, $p->effectiveDate)->available;
            $limit = Units::normalize($account->getRawOriginal('negative_limit'));
            if (Units::cmp(Units::add($available, $units), Units::neg($limit)) < 0) {
                throw LedgerRejected::insufficient($available, Units::abs($units), $limit);
            }
        }
        if ($p->allocations) {
            $this->assertAllocations($account, $p, $units);
        }

        $entry = LeaveLedgerEntry::create([
            'account_id' => $account->id, 'employee_id' => $account->employee_id, 'leave_type_id' => $account->leave_type_id,
            'entry_type' => $p->entryType, 'bucket' => $p->bucket, 'units' => $units, 'effective_date' => $p->effectiveDate, 'expires_on' => $p->expiresOn,
            'idempotency_key' => $p->idempotencyKey, 'reverses_entry_id' => $p->reversesEntryId, 'source_entry_id' => $p->sourceEntryId,
            'request_id' => $p->requestId, 'segment_id' => $p->segmentId, 'accrual_item_id' => $p->accrualItemId, 'comp_off_credit_id' => $p->compOffCreditId,
            'import_row_id' => $p->importRowId, 'policy_version_id' => $p->policyVersionId, 'reference_type' => $p->referenceType, 'reference_id' => $p->referenceId,
            'reason' => $p->reason, 'meta' => $p->meta, 'created_by' => $p->createdBy, 'posted_at' => now(),
        ]);
        foreach ($p->allocations as $a) {
            LeaveLedgerAllocation::create(['entry_id' => $entry->id, 'source_entry_id' => $a['source_entry_id'], 'units' => Units::strict($a['units'])]);
        }

        return new PostResult($entry, false);
    }

    /** Draw-downs must come from credit lots of THIS account with enough units left, and cannot exceed the debit. */
    private function assertAllocations(LeaveAccount $account, LedgerPosting $p, string $units): void
    {
        $lots = collect($this->balances->lots($account, $p->effectiveDate))->keyBy('entry_id');
        $total = '0.0000';
        foreach ($p->allocations as $a) {
            $lot = $lots->get($a['source_entry_id']);
            $take = Units::strict($a['units']);
            if (! $lot || Units::cmp($take, '0') <= 0 || Units::cmp($take, $lot['remaining']) > 0) {
                throw LedgerRejected::invalid('Allocation exceeds what is left in the credit lot (or the lot is not usable).');
            }
            $total = Units::add($total, $take);
        }
        if (Units::cmp($total, Units::abs($units)) > 0) {
            throw LedgerRejected::invalid('Allocations exceed the debit.');
        }
    }

    private function asDuplicate(LeaveLedgerEntry $existing, ?LedgerPosting $p, ?int $accountId = null): PostResult
    {
        if ($p && ((int) $existing->account_id !== $p->accountId || Units::cmp($existing->getRawOriginal('units'), $p->units) !== 0 || $existing->bucket !== $p->bucket)) {
            throw LedgerRejected::idempotencyConflict($p->idempotencyKey);
        }
        if (! $p && $accountId !== null && (int) $existing->account_id !== $accountId) {
            throw LedgerRejected::idempotencyConflict($existing->idempotency_key);
        }

        return new PostResult($existing, true);
    }
}
