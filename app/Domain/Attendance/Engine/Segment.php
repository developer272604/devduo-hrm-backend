<?php

namespace App\Domain\Attendance\Engine;

use Carbon\CarbonImmutable;

/** A scheduled interval inside a day: a work segment or a (paid/unpaid) break, expressed in UTC. */
final class Segment
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly string $kind = 'work',   // work | break
        public readonly bool $paid = true,
    ) {}

    public function minutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    public function toArray(): array
    {
        return ['start' => $this->start->utc()->toIso8601String(), 'end' => $this->end->utc()->toIso8601String(), 'kind' => $this->kind, 'paid' => $this->paid];
    }
}
