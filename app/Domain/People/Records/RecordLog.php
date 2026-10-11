<?php

namespace App\Domain\People\Records;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvents;
use App\Domain\People\Models\Employee;

/**
 * One place for the audit and domain-event conventions of the sensitive-records area.
 * Callers pass only safe facts (ids, types, counts, flags). Identifier numbers, bank values and file contents never belong here.
 */
final class RecordLog
{
    public static function audit(string $action, Employee $employee, string $section, array $context = []): void
    {
        Audit::record($action, 'people', 'employee:'.$employee->uuid, ['section' => $section] + $context);
    }

    /** @param array{0:string,1:int}|null $subject */
    public static function event(string $type, Employee $employee, string $summary, string $classification, ?array $subject = null, array $payload = [], ?string $effectiveDate = null): void
    {
        app(DomainEvents::class)->record($type, ['employee' => $employee, 'summary' => $summary, 'classification' => $classification, 'subject' => $subject, 'payload' => $payload ?: null, 'effective_date' => $effectiveDate]);
    }
}
