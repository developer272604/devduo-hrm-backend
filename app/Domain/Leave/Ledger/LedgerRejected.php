<?php

namespace App\Domain\Leave\Ledger;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** A posting the ledger refuses. `reason` is a stable machine code; the message is safe to show to the user. */
final class LedgerRejected extends HttpException
{
    public function __construct(public readonly string $reason, string $message, int $status = 422)
    {
        parent::__construct($status, $message);
    }

    public static function insufficient(string $available, string $needed, string $limit): self
    {
        return new self('insufficient_balance', "Not enough leave balance: $available available, $needed needed".(bccomp($limit, '0', 4) > 0 ? " (negative balance allowed down to -$limit)." : '.'));
    }

    public static function idempotencyConflict(string $key): self
    {
        return new self('idempotency_conflict', "Idempotency key \"$key\" was already used for a different posting.", 409);
    }

    public static function invalid(string $message): self
    {
        return new self('invalid_posting', $message);
    }

    public static function alreadyReversed(): self
    {
        return new self('already_reversed', 'This ledger entry has already been reversed.', 409);
    }

    public static function notReversible(): self
    {
        return new self('not_reversible', 'A reversal entry cannot itself be reversed; post a new adjustment instead.', 409);
    }

    public static function accountClosed(): self
    {
        return new self('account_closed', 'This leave account belongs to a closed period.', 409);
    }
}
