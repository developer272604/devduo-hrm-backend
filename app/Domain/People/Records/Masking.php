<?php

namespace App\Domain\People\Records;

/** Display masks for highly sensitive values. Only fragments such as the last four characters ever reach list APIs. */
final class Masking
{
    public const BULLETS = '••••';

    /** @param string $rule last4 | last2 | full */
    public static function fromLast4(?string $last4, string $rule = 'last4'): string
    {
        $last4 = (string) $last4;

        return match ($rule) {
            'full' => self::BULLETS.self::BULLETS,
            'last2' => self::BULLETS.mb_substr($last4, -2),
            default => self::BULLETS.$last4,
        };
    }

    public static function value(string $value, string $rule = 'last4'): string
    {
        return self::fromLast4(mb_substr($value, -4), $rule);
    }
}
