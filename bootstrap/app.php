<?php

use App\Http\Middleware\RequireModule;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\TenantSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m) {
        $m->alias(['tenant' => TenantSession::class, 'permission' => RequirePermission::class, 'module' => RequireModule::class, 'device' => \App\Http\Middleware\DeviceAuthentication::class]);
        // Attendance devices authenticate with a hashed per-device token, not a browser session.
        $m->validateCsrfTokens(except: ['api/device/*']);
    })
    ->withExceptions(function (Exceptions $e) {
        $e->shouldRenderJsonWhen(fn ($r, $e) => $r->is('api/*') || $r->expectsJson());
    })->create();
