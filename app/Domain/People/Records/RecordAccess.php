<?php

namespace App\Domain\People\Records;

use App\Domain\Access\DataScope;
use App\Domain\Access\FieldAccess;
use App\Domain\People\Models\Employee;
use App\Models\User;

/**
 * Authorization helpers shared by the sensitive-records endpoints. The caller has already resolved the employee
 * through the data scope (404 for out-of-reach subjects); everything here decides what the viewer may do inside it.
 */
final class RecordAccess
{
    public function __construct(private DataScope $scope, private FieldAccess $access) {}

    public function isSelf(User $user, Employee $employee): bool
    {
        return $this->scope->isSelf($user, $employee);
    }

    /** Whether the viewer holds a permission that reaches this employee. */
    public function reaches(User $user, Employee $employee, string $permission): bool
    {
        return $user->allows($permission) && $this->scope->canAccess($user, $employee, $permission);
    }

    /**
     * Whether the viewer may see the MASKED form of a highly sensitive section (identity, work authorization, bank).
     * Lists never return more than the masked form; unmasking is a separate, audited reveal. Holders of the matching manage
     * permission always get the masked view so HR and payroll administrators can maintain the records.
     */
    public function canView(User $user, Employee $employee, string $field, string $managePermission): bool
    {
        $mode = $this->access->view($user, $field, $employee);
        if ($this->isSelf($user, $employee)) {
            return $mode !== FieldAccess::HIDDEN;
        }
        if ($mode === FieldAccess::HIDDEN) {
            return $this->reaches($user, $employee, $managePermission);
        }
        foreach (['people.sensitive.reveal', 'people.sensitive.view', 'people.confidential.view', $managePermission] as $permission) {
            if ($this->reaches($user, $employee, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function canReveal(User $user, Employee $employee): bool
    {
        return $this->reaches($user, $employee, 'people.sensitive.reveal');
    }

    /**
     * Resolves a write for a field group: returns 'direct' or aborts with 409 change_request_required (the change-request
     * workflow applies it later) or 403.
     */
    public function requireEdit(User $user, Employee $employee, string $group, string $section): string
    {
        $mode = $this->access->editGroup($user, $group, $employee);
        if ($mode === 'request') {
            abort(response()->json(['message' => 'This change needs approval before it applies.', 'code' => 'change_request_required', 'section' => $section], 409));
        }
        abort_if($mode !== 'direct', 403, 'You cannot change this information.');

        return $mode;
    }

    /** True when the actor is HR acting on someone else's record (as opposed to an employee editing their own). */
    public function isHrEdit(User $user, Employee $employee, string $managePermission): bool
    {
        return $this->reaches($user, $employee, $managePermission) && $this->reaches($user, $employee, 'people.manage');
    }
}
