<?php

namespace App\Domain\Performance\Contracts;

/**
 * Read-only contract through which later phases (Phase 5 compensation revisions, Phase 7 promotions / succession) consume finalized review outcomes.
 * It exposes facts only (final score, rating label, period). It never changes salary, grade or employment: any resulting decision must go through
 * that module's own human-approved workflow. Only finalized or acknowledged reviews are returned; drafts and calibration work are never visible.
 */
interface PerformanceOutcomeProvider
{
    /**
     * @return array<int,array{review:string,cycle:string,cycle_name:string,period_start:string,period_end:string,final_score:string,final_rating:?string,scale_min:string,scale_max:string,finalized_at:string}>
     *                                                                                                                                                                                      newest first
     */
    public function outcomesFor(int $employeeId, ?string $asOfDate = null): array;

    /** Latest finalized outcome as of a date, or null. */
    public function latest(int $employeeId, ?string $asOfDate = null): ?array;
}
