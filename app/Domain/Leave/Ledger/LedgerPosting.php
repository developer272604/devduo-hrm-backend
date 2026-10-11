<?php

namespace App\Domain\Leave\Ledger;

/**
 * A request to append one ledger entry. `units` is SIGNED (credit > 0, debit < 0) and a decimal string with at most 4 places.
 * `idempotencyKey` is mandatory: retrying a post with the same key returns the original entry and never writes twice.
 */
final class LedgerPosting
{
    /**
     * @param  array<int,array{source_entry_id:int,units:string}>  $allocations  credit lots a debit draws on (source-to-use trace); units positive
     * @param  array<string,mixed>|null  $meta  calculation trace (inputs, rule, formula) stored with the entry
     */
    public function __construct(
        public readonly int $accountId,
        public readonly string $entryType,
        public readonly string $bucket,
        public readonly string $units,
        public readonly string $effectiveDate,
        public readonly string $idempotencyKey,
        public readonly ?string $expiresOn = null,
        public readonly ?int $requestId = null,
        public readonly ?int $segmentId = null,
        public readonly ?int $accrualItemId = null,
        public readonly ?int $compOffCreditId = null,
        public readonly ?int $importRowId = null,
        public readonly ?int $policyVersionId = null,
        public readonly ?int $sourceEntryId = null,
        public readonly ?string $referenceType = null,
        public readonly ?string $referenceId = null,
        public readonly ?string $reason = null,
        public readonly ?array $meta = null,
        public readonly ?int $createdBy = null,
        /** When true a debit may not take `available` below -negative_limit (reservations, consumption of unreserved leave). */
        public readonly bool $enforceAvailable = false,
        /** Skip the leave-lock check. Only system postings that must land in a locked period (never user actions) may set this. */
        public readonly bool $ignoreLock = false,
        /** Allow posting to a closed account (expiry, carry-forward out, reversals during period close). */
        public readonly bool $allowClosed = false,
        public readonly array $allocations = [],
        /** Set by LedgerService::reverse(): the entry this posting reverses (one reversal per entry, enforced by a unique index). */
        public readonly ?int $reversesEntryId = null,
    ) {}
}
