<?php

namespace App\Domain\Integrations\Imports;

/** A run stopped for a known reason. $retryable runs are re-attempted automatically by integrations:run-due (bounded). */
final class RunFailed extends \RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
