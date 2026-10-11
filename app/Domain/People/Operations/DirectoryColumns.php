<?php

namespace App\Domain\People\Operations;

use App\Domain\Access\FieldAccess;
use App\Domain\People\Services\EmployeeDirectory;
use App\Models\User;

/** Column catalogue of the People Directory (for column customization and saved views). */
final class DirectoryColumns
{
    /** key (as returned by the directory API) => [label, field registry key, default visible, sort key or null] */
    public const COLUMNS = [
        'display_name' => ['Name', 'person.display_name', true, 'name'],
        'employee_number' => ['Employee number', 'employee.employee_number', true, 'employee_number'],
        'designation' => ['Designation', 'job.designation', true, 'designation'],
        'department' => ['Department', 'job.department', true, 'department'],
        'location' => ['Location', 'job.location', true, 'location'],
        'manager' => ['Manager', 'job.manager', true, null],
        'employee_type' => ['Employee type', 'job.employee_type', true, null],
        'status' => ['Status', 'employee.status', true, 'status'],
        'joined_on' => ['Joining date', 'employee.joined_on', true, 'joined_on'],
        'work_email' => ['Work email', 'employee.work_email', true, null],
        'work_phone' => ['Work phone', 'employee.work_phone', false, null],
        'worker_type' => ['Worker type', 'employee.worker_type', false, null],
        'preferred_name' => ['Preferred name', 'person.preferred_name', false, null],
    ];

    /** @return array<int,array{key:string,label:string,default_visible:bool,sortable:bool,sort_key:?string}> */
    public static function forUser(User $user): array
    {
        $access = app(FieldAccess::class);
        $out = [];
        foreach (self::COLUMNS as $key => [$label, $field, $default, $sort]) {
            if ($access->canView($user, $field, null) || $user->allows('people.personal.view')) {
                $out[] = ['key' => $key, 'label' => $label, 'default_visible' => $default, 'sortable' => $sort !== null, 'sort_key' => $sort];
            }
        }

        return $out;
    }

    public static function sortKeys(): array
    {
        return array_keys(EmployeeDirectory::SORTS);
    }
}
