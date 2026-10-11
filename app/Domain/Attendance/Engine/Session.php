<?php

namespace App\Domain\Attendance\Engine;

/** One paired work session (IN -> OUT). `seconds` is already clamped by pairing.max_session_minutes. */
final class Session
{
    public function __construct(
        public readonly Punch $in,
        public readonly Punch $out,
        public readonly int $seconds,
        public readonly bool $autoFilled = false,
        public readonly bool $capped = false,
    ) {}

    public function minutes(): int
    {
        return intdiv($this->seconds, 60);
    }

    /** @return array{0:int,1:int} */
    public function interval(): array
    {
        return [$this->in->at->getTimestamp(), $this->in->at->getTimestamp() + $this->seconds];
    }

    public function toArray(): array
    {
        return ['in' => $this->in->at->utc()->toIso8601String(), 'out' => $this->out->at->utc()->toIso8601String(), 'minutes' => $this->minutes()];
    }
}
