<?php

namespace App\Domain\Payroll\Contracts;

use App\Domain\Payroll\Contracts\Dto\StatutoryContext;
use App\Domain\Payroll\Contracts\Dto\StatutoryResult;

/**
 * Country statutory calculation interface. The GLOBAL engine never contains one country law: it hands a StatutoryContext (facts
 * about the employee, the period and the rule version that applies) to the pack handler named by the country pack and receives
 * statutory lines back. A handler is either the generic declarative interpreter (rules JSON of the published pack version) or a
 * country-specific class. Register handlers in a Boot class: app(StatutoryPackRegistry::class)->register('key', Handler::class).
 *
 * A handler must be pure for a given context: same context (including rules version) => same result. Statutory rule
 * implementations require expert legal validation before production enablement (see payroll_country_pack_versions.verified_at).
 */
interface StatutoryPack
{
    /** Stable handler key stored in payroll_country_packs.handler. */
    public function handler(): string;

    /** Calculates taxes / contributions / limits for one employee and period. Never writes. */
    public function calculate(StatutoryContext $context): StatutoryResult;

    /**
     * Data checks before calculation (missing statutory identifiers, invalid declarations, ...). Returns issues shaped
     * [{code, severity: information|warning|blocking, message, field?}] with user-facing messages.
     *
     * @return array<int,array<string,mixed>>
     */
    public function validate(StatutoryContext $context): array;

    /** Statutory identifier definitions required by this pack version [{key,label,required,pattern?}]. @return array<int,array<string,mixed>> */
    public function identifiers(array $rules): array;

    /** Report / filing keys this pack can produce (consumed by the statutory reports framework). @return array<int,string> */
    public function reports(array $rules): array;
}
