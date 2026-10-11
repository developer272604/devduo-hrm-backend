<?php

namespace App\Domain\Leave\Ledger;

use App\Domain\Leave\Models\LeaveLedgerEntry;

/** Outcome of LedgerService::post(). `duplicate` is true when the idempotency key had already been posted (nothing was written). */
final class PostResult
{
    public function __construct(public readonly LeaveLedgerEntry $entry, public readonly bool $duplicate) {}
}
