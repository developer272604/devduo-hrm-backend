<?php

namespace App\Domain\Leave\Contracts\Dto;

/** One day (or part-day) of a request: maps 1:1 to a leave_request_segments row. `units` are decimal strings (4 dp). */
final class DurationSegment
{
    public function __construct(
        public readonly string $date,
        public readonly string $portion,
        public readonly int $minutes,
        public readonly string $units,
        public readonly bool $charged,
        /** workday | holiday | weekly_off | sandwiched | partial */
        public readonly string $dayKind,
        /** paid | unpaid | statutory | lop | comp_off */
        public readonly string $payClass,
        /** Human reason for this day, e.g. "Sunday is a weekly off in the employee's work week - not charged". */
        public readonly string $explanation,
        public readonly ?string $startsAt = null,
        public readonly ?string $endsAt = null,
        /** @var array<string,mixed> machine-readable inputs (shift id, holiday id, rule id) */
        public readonly array $detail = [],
    ) {}
}
