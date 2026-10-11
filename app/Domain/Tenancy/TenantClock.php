<?php

namespace App\Domain\Tenancy;

use App\Models\Tenant;
use Carbon\CarbonImmutable;

/** Tenant-local "today" for date-only business rules. Employee-local rules should pass the employee's timezone. */
final class TenantClock
{
    public static function timezone(): string
    {
        $id = app(TenantContext::class)->id;
        $tenant = $id ? Tenant::find($id) : null;

        return $tenant?->settings['timezone'] ?? config('app.timezone');
    }

    public static function today(?string $timezone = null): string
    {
        return CarbonImmutable::now($timezone ?? self::timezone())->toDateString();
    }

    public static function setting(string $path, mixed $default = null): mixed
    {
        $id = app(TenantContext::class)->id;

        return data_get($id ? Tenant::find($id)?->settings : [], $path, $default);
    }
}
