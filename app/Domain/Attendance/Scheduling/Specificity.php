<?php

namespace App\Domain\Attendance\Scheduling;

/**
 * The published specificity ranking shared by every effective-dated, polymorphic assignment (shift, work week, holiday
 * calendar). The attendance-policy resolver may mirror it. Resolution order for one date:
 *   specificity rank DESC, is_override DESC, priority DESC, effective_from DESC, id DESC.
 * Anything equal on the first four keys is an AMBIGUOUS configuration: resolution stays deterministic (id) but is reported.
 */
final class Specificity
{
    public const EMPLOYEE = 100;

    public const TEAM = 80;

    public const DEPARTMENT = 70;

    public const EMPLOYEE_GROUP = 60;

    public const BUSINESS_UNIT = 50;

    public const LOCATION = 40;

    public const EMPLOYEE_TYPE = 30;

    public const LEGAL_ENTITY = 20;

    public const COUNTRY = 10;

    public const TENANT = 0;

    public const RANKS = [
        'employee' => self::EMPLOYEE, 'team' => self::TEAM, 'department' => self::DEPARTMENT, 'employee_group' => self::EMPLOYEE_GROUP,
        'business_unit' => self::BUSINESS_UNIT, 'location' => self::LOCATION, 'employee_type' => self::EMPLOYEE_TYPE,
        'legal_entity' => self::LEGAL_ENTITY, 'country' => self::COUNTRY, 'tenant' => self::TENANT,
    ];

    public static function rank(string $targetType): int
    {
        return self::RANKS[$targetType] ?? -1;
    }

    public static function types(): array
    {
        return array_keys(self::RANKS);
    }

    /** Human label used in resolution notes and validation messages. */
    public static function label(string $targetType): string
    {
        return str_replace('_', ' ', $targetType);
    }
}
