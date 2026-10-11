<?php

namespace App\Domain\Leave\Operations\Bulk;

use App\Models\User;

interface LeaveBulkAction
{
    public function key(): string;

    public function label(): string;

    public function description(): string;

    /** Permission needed to run it (also bounds the data scope of the selected employees). */
    public function permission(): string;

    /** @return array<string,mixed> validated params (throws ValidationException) */
    public function validate(array $params): array;

    /**
     * @param  array<int,object>  $employees  employee rows (id, uuid, employee_number, display_name) already limited to the user's scope
     * @return array<int,array{status:string,reason?:string,detail?:mixed}>  keyed by employee id; status ok|warning|error|skip
     */
    public function preview(User $user, array $employees, array $params): array;

    /** @return array{status:string,reason?:string,detail?:mixed} */
    public function run(User $user, object $employee, array $params): array;
}
