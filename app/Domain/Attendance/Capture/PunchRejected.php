<?php

namespace App\Domain\Attendance\Capture;

/** A punch was refused for a business reason (friendly message, stable code). Controllers map it to HTTP; batch callers to a per-row error. */
class PunchRejected extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render()
    {
        return response()->json(['message' => $this->getMessage(), 'reason' => $this->reason], $this->status);
    }
}
