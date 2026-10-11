<?php

namespace App\Domain\Quality;

use App\Domain\Access\Classification;

/**
 * One detected data-quality problem, as yielded by a QualityRule. The engine turns it into (or refreshes) a
 * data_quality_findings row keyed by (tenant, rule, fingerprint).
 *
 * The fingerprint is the stable identity of the problem ("the same issue about the same record"): it must NOT contain
 * anything that changes while the problem persists (counts, dates of detection), otherwise the issue would be reported
 * as new on every scan and a dismissal would not stick.
 */
final class Finding
{
    public const SEVERITIES = ['info', 'warning', 'action_required', 'critical'];

    /**
     * @param  array{0:string,1:int}|null  $subject  affected record as [type, id] (e.g. ['employee_document', 12])
     * @param  array<string,mixed>  $details  structured explanation for the UI; never put secrets (ID numbers, bank data) in here
     * @param  string  $classification  Classification class of the data described: decides who may see the finding
     */
    public function __construct(
        public readonly ?int $employeeId,
        public readonly string $fingerprint,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly string $severity = 'warning',
        public readonly ?array $subject = null,
        public readonly array $details = [],
        public readonly string $classification = Classification::HR_CONFIDENTIAL,
    ) {
        if (! in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException('Unknown severity '.$severity);
        }
    }
}
