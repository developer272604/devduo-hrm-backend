<?php

namespace App\Domain\Quality;

/**
 * A data-quality check. Modules register rules with DataQualityEngine::register().
 *
 * scan() must be READ-ONLY (a rule never corrects HR data), must only read the given tenant, and should stream
 * results (chunk large tables; do not load a whole tenant into memory). Findings must be stable: the same problem
 * yields the same fingerprint on every scan.
 */
interface QualityRule
{
    /** Stable machine key stored in data_quality_findings.rule, e.g. "missing_manager". Max 80 characters. */
    public function key(): string;

    /** Category shown in filters, e.g. completeness | organization | duplicates | documents | effective_dating | reporting | employment | country_compliance | contact_info. */
    public function category(): string;

    /** Human label of the rule (for the UI and summaries). */
    public function label(): string;

    /** @return iterable<Finding> */
    public function scan(int $tenantId): iterable;
}
