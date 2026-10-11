<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequirePermission
{
    public function handle(Request $r, Closure $next, string $permission)
    {
        abort_unless(collect(explode('|', $permission))->contains(fn ($key) => $r->user()->allows($key)), 403, 'You do not have access to this area.');

        return $next($r);
    }
}
