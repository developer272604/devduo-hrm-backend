<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireModule
{
    public function handle(Request $r, Closure $next, string $module)
    {
        abort_unless(in_array($module, $r->user()->tenant->settings['modules'] ?? ['work', 'notifications'], true), 403, 'This module is disabled for your workspace.');

        return $next($r);
    }
}
