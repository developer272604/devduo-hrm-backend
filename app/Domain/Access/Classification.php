<?php

namespace App\Domain\Access;

/**
 * Data classification. It drives API serialization, UI visibility, exports, audit and search.
 *
 * Classes are not a strict ladder: "manager_sensitive" is visible to the line manager and HR but NOT to the
 * employee, while "employee" is visible to the employee and HR but not to the manager.
 */
final class Classification
{
    public const PUBLIC_INTERNAL = 'public_internal';

    public const EMPLOYEE = 'employee';

    public const MANAGER_SENSITIVE = 'manager_sensitive';

    public const HR_CONFIDENTIAL = 'hr_confidential';

    public const HIGHLY_SENSITIVE = 'highly_sensitive';

    public const SYSTEM_RESTRICTED = 'system_restricted';

    public const ALL = [self::PUBLIC_INTERNAL, self::EMPLOYEE, self::MANAGER_SENSITIVE, self::HR_CONFIDENTIAL, self::HIGHLY_SENSITIVE, self::SYSTEM_RESTRICTED];

    public const LABELS = [
        self::PUBLIC_INTERNAL => 'Public internal', self::EMPLOYEE => 'Employee', self::MANAGER_SENSITIVE => 'Manager sensitive',
        self::HR_CONFIDENTIAL => 'HR confidential', self::HIGHLY_SENSITIVE => 'Highly sensitive', self::SYSTEM_RESTRICTED => 'System restricted',
    ];

    /** Classes that need an explicit export permission and are audited when exported. */
    public const EXPORT_SENSITIVE = [self::HR_CONFIDENTIAL, self::HIGHLY_SENSITIVE];

    public static function valid(string $class): bool
    {
        return in_array($class, self::ALL, true);
    }
}
