<?php

namespace App\Domain\Security\Contracts;

/**
 * Retention/purge jobs consult this before deleting or anonymising data. A hold with module=null covers the whole
 * workspace; subject_ref (for example "candidate:<uuid>") narrows a hold to one record. Callers without a subject
 * (batch jobs) are only blocked by workspace/module-wide holds; per-record purge loops should pass the subject.
 */
interface LegalHoldChecker
{
    public function isHeld(string $module, ?string $subjectRef = null): bool;
}
