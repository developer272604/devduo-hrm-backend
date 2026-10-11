<?php

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }

    public function boot(): void
    {
        ResetPassword::createUrlUsing(fn ($u, $token) => rtrim(config('app.frontend_url'), '/').'/reset-password?token='.urlencode($token).'&email='.urlencode($u->email));
    }
}
