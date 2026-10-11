<?php

namespace App\Domain\Attendance\Capture;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/** Instants are stored as UTC wall-clock text whatever the application timezone is; reads return UTC CarbonImmutable. */
class UtcInstant implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : CarbonImmutable::createFromFormat('Y-m-d H:i:s', substr(str_replace('T', ' ', (string) $value), 0, 19), 'UTC');
    }

    public function set($model, string $key, $value, array $attributes)
    {
        if ($value === null) {
            return null;
        }
        $instant = $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC');

        return $instant->utc()->format('Y-m-d H:i:s');
    }
}
