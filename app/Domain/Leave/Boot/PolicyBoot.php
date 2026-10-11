<?php

namespace App\Domain\Leave\Boot;

use App\Domain\Leave\Contracts\LeavePolicyResolver;
use App\Domain\Leave\Policies\PolicyResolver;
use Illuminate\Contracts\Foundation\Application;

/** Workstream A bindings: the leave policy resolver (same precedence as attendance policy resolution). */
final class PolicyBoot
{
    public static function register(Application $app): void
    {
        $app->bind(LeavePolicyResolver::class, PolicyResolver::class);
    }
}
