<?php

namespace App\Domain\Attendance\Operations\Quality;

use App\Domain\Quality\Finding;
use App\Domain\Quality\QualityRule;

/** A data-quality rule defined by a key/label and a generator. Read-only: rules report, they never change records. */
final class AttendanceQualityRule implements QualityRule
{
    /** @param \Closure(int):iterable<Finding> $scanner */
    public function __construct(private string $key, private string $label, private \Closure $scanner, private string $category = 'attendance') {}

    public function key(): string
    {
        return $this->key;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function scan(int $tenantId): iterable
    {
        return ($this->scanner)($tenantId);
    }
}
