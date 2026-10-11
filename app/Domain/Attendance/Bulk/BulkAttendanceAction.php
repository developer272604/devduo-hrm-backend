<?php

namespace App\Domain\Attendance\Bulk;

use App\Models\User;

/**
 * One bulk attendance operation. Every bulk run is: validate -> PREVIEW (no writes) -> confirm (signed token) -> apply
 * per target in its own transaction -> partial-failure report -> audit.
 *
 * A "target" is an array describing what the action touches, e.g. {employee_id, date} or {employee_id}
 * or {exception_id}; the action declares its kind. Out-of-scope targets must be reported as not found.
 *
 * Actions register themselves with BulkAttendanceActions::register() from their own Boot class.
 */
interface BulkAttendanceAction
{
    /** Stable key, e.g. "assign_shift", "recalculate", "regularize", "mark_on_duty", "resolve_exception", "lock", "unlock". */
    public function key(): string;

    public function label(): string;

    /** Permission required IN ADDITION to attendance.bulk (e.g. attendance.roster.manage, attendance.unlock). */
    public function permission(): string;

    /** "employee_date" | "employee" | "exception" | "lock" */
    public function targetKind(): string;

    /** Validate and normalize parameters; throw Illuminate\Validation\ValidationException with friendly messages. */
    public function validate(array $params): array;

    /** @return array{status:string,message:string,impact?:array} status: ok | skipped | error; MUST NOT write. */
    public function preview(User $actor, array $target, array $params): array;

    /** @return array{status:string,message:string} performed inside the caller's per-target transaction. */
    public function apply(User $actor, array $target, array $params): array;
}
