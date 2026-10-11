<?php

namespace App\Domain\People\Boot;

use App\Domain\People\Config\CustomFieldService;
use Illuminate\Contracts\Foundation\Application;

/** Bindings for the People admin configuration area (lookups, custom fields, groups, data-scope grants). */
final class ConfigBoot
{
    public static function register(Application $app): void
    {
        $app->singleton(CustomFieldService::class);
    }
}
