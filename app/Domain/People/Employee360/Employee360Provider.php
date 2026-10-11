<?php

namespace App\Domain\People\Employee360;

use App\Domain\People\Models\Employee;
use App\Models\User;

/**
 * A module's contribution to Employee 360. Register with Employee360::register().
 *
 * sections() returns a list of sections for this viewer and employee:
 *   {key, title, order (int, lower first), source (module name), items: [{key, label, value?, detail?, date?, href?, source}]}
 * Rules for providers: (1) respect permissions - return nothing the viewer could not open elsewhere (use FieldAccess with the
 * given $relation); (2) facts only, each with its source - never conclusions about behaviour or performance; (3) omit empty sections.
 */
interface Employee360Provider
{
    public function key(): string;

    /**
     * @param  array{self:bool,manager:bool}  $relation  the viewer's relation to the employee, computed once per request
     * @return array<int,array<string,mixed>>
     */
    public function sections(User $viewer, Employee $employee, array $relation): array;
}
