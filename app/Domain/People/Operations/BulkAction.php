<?php

namespace App\Domain\People\Operations;

use App\Domain\People\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * One bulk action. Register an implementation with BulkActions::register() from a Boot class.
 * Optional: implement DescribesBulkAction (label/description) and BulkBatchAction (one call for the whole set).
 */
interface BulkAction
{
    public function key(): string;

    /** Permission required in addition to people.bulk. */
    public function permission(): string;

    /** Validates and normalizes parameters; throw Illuminate\Validation\ValidationException on bad input. */
    public function validate(array $params): array;

    /**
     * Dry run per employee.
     *
     * @param  Collection<int,Employee>  $employees
     * @return array<int,array{status:string,reason?:?string}> keyed by employee id; status ok|skipped|error
     */
    public function preview(User $user, Collection $employees, array $params): array;

    /** Applies the change to one employee (inside its own transaction). Throw to report failure. */
    public function apply(User $user, Employee $employee, array $params): void;
}
