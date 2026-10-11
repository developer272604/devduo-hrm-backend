<?php

namespace App\Domain\Insights;

use App\Models\User;

/**
 * A module's contribution to the Action Center. Register with ActionCenter::register().
 *
 * insights() returns only conditions that currently need attention (count > 0), already limited to what THIS viewer may see
 * (permission + data scope + field-level rules). Each insight is an array:
 *   key          stable id, "<domain>.<name>"
 *   domain       "people", "attendance", ...
 *   severity     info | warning | action_required | critical
 *   title        sentence with the count, e.g. "7 employees have missing mandatory documents"
 *   count        int > 0
 *   description  one explanatory sentence
 *   actions      NON-EMPTY list of {label, kind: navigate|api, href | endpoint+method [+ body], requires_confirmation: bool}
 *   sample       up to 5 of {employee_uuid, name}
 * ActionCenter drops insights that are malformed or have no action, so every insight shown is actionable.
 */
interface InsightProvider
{
    public function key(): string;

    /** @return array<int,array<string,mixed>> */
    public function insights(User $viewer): array;
}
