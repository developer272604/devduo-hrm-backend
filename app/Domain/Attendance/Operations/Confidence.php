<?php

namespace App\Domain\Attendance\Operations;

/**
 * Attendance confidence: data completeness of a day or period (NOT a performance score). One vocabulary for every output.
 * Stored codes on attendance_days.confidence: confirmed | review_required | pending.
 */
final class Confidence
{
    public const CONFIRMED = 'confirmed';

    public const REVIEW_REQUIRED = 'review_required';

    public const PENDING = 'pending';

    public const LABELS = [self::CONFIRMED => 'CONFIRMED', self::REVIEW_REQUIRED => 'REVIEW REQUIRED', self::PENDING => 'PENDING'];

    public const MEANING = [
        self::CONFIRMED => 'All required punches and rules are resolved.',
        self::REVIEW_REQUIRED => 'Something needs a human decision (an exception, a missing punch or a mismatch).',
        self::PENDING => 'The day is not finished or not processed yet.',
    ];

    /** Least complete wins: pending < review_required < confirmed. */
    public static function worst(iterable $codes): string
    {
        $rank = [self::PENDING => 0, self::REVIEW_REQUIRED => 1, self::CONFIRMED => 2];
        $worst = null;
        foreach ($codes as $code) {
            $code = isset($rank[$code]) ? $code : self::PENDING;
            if ($worst === null || $rank[$code] < $rank[$worst]) {
                $worst = $code;
            }
        }

        return $worst ?? self::PENDING;
    }

    /** @return array{code:string,label:string,meaning:string} */
    public static function describe(string $code): array
    {
        $code = isset(self::LABELS[$code]) ? $code : self::PENDING;

        return ['code' => $code, 'label' => self::LABELS[$code], 'meaning' => self::MEANING[$code]];
    }
}
