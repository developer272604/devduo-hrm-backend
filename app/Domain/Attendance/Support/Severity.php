<?php

namespace App\Domain\Attendance\Support;

/** Exception / finding severities. Do not mark normal operational exceptions as critical. */
final class Severity
{
    public const INFO = 'info';

    public const WARNING = 'warning';

    public const ACTION_REQUIRED = 'action_required';

    public const CRITICAL = 'critical';

    public const ORDER = [self::INFO => 1, self::WARNING => 2, self::ACTION_REQUIRED => 3, self::CRITICAL => 4];

    public static function max(string $a, string $b): string
    {
        return (self::ORDER[$a] ?? 0) >= (self::ORDER[$b] ?? 0) ? $a : $b;
    }
}
