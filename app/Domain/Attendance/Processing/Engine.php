<?php

namespace App\Domain\Attendance\Processing;

/** Thin access to the calculation engine (owned by the engine workstream) so processing never depends on its internals. */
final class Engine
{
    public const CALCULATOR = 'App\Domain\Attendance\Engine\AttendanceCalculator';

    /** The calculator instance (resolvable from the container; tests may bind a fake with a calculate(CalculationInput) method). */
    public static function calculator(): object
    {
        return app(self::CALCULATOR);
    }

    /** Engine version stored with every calculation. config('attendance.engine_version') overrides (tests). */
    public static function version(): string
    {
        if ($override = config('attendance.engine_version')) {
            return (string) $override;
        }
        $constant = self::CALCULATOR.'::ENGINE_VERSION';

        return defined($constant) ? (string) constant($constant) : 'unversioned';
    }
}
