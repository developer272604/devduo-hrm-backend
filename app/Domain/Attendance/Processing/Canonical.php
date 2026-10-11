<?php

namespace App\Domain\Attendance\Processing;

/** Canonical JSON (map keys sorted recursively, list order kept) so hashes of equal data are equal. */
final class Canonical
{
    public static function value(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = array_map(fn ($v) => self::value($v), $value);
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }

    public static function json(mixed $value): string
    {
        return json_encode(self::value($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }
}
