<?php

namespace App\Domain\Recruitment\Contracts;

/**
 * Neutral integration point for job boards, career sites, email/calendar and external ATS tools. No vendor is implemented in the core:
 * the default binding records nothing and returns no candidates. Implementations must not receive unmasked sensitive data beyond what the
 * method signature carries.
 */
interface CandidateChannelAdapter
{
    /** Publish (or withdraw) a posting on an external channel. Returns an external reference or null when nothing was published. */
    public function publishPosting(array $posting): ?string;

    /** Notify an external calendar/mail tool that an interview was scheduled. Returns an external reference or null. */
    public function interviewScheduled(array $interview): ?string;
}
