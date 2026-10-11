<?php

namespace App\Domain\Attendance\Processing;

/** A day could not be processed. `cause` groups failures in run reports; the message is always safe to show to users. */
final class ProcessingFailed extends \RuntimeException
{
    public const CALCULATION = 'calculation_error';

    public const SCHEDULE = 'schedule_error';

    public function __construct(public readonly string $cause, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
