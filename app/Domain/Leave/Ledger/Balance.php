<?php

namespace App\Domain\Leave\Ledger;

/**
 * Derived balance of one leave account. All values are 4-dp decimal strings and displayed as POSITIVE magnitudes except
 * `adjusted` and `carried` which keep their sign (a carry-forward-out on a closed account is negative).
 *
 *   entitled  = opening + accrued + carried + adjusted
 *   available = entitled - consumed - pending - scheduled - expired - encashed      (== sum of all signed entries counted)
 *
 * `trace` lists the ledger entry ids behind each bucket so every number can be explained.
 */
final class Balance
{
    /** @param array<string,array<int,int>> $trace bucket => entry ids */
    public function __construct(
        public readonly int $accountId,
        public readonly string $opening,
        public readonly string $accrued,
        public readonly string $carried,
        public readonly string $adjusted,
        public readonly string $consumed,
        public readonly string $pending,
        public readonly string $scheduled,
        public readonly string $expired,
        public readonly string $encashed,
        public readonly string $entitled,
        public readonly string $available,
        public readonly string $asOf,
        public readonly array $trace = [],
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
