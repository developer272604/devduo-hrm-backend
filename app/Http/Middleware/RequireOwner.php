<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RequireOwner
{
    public function handle(Request $r, Closure $next)
    {
        abort_unless($r->user()?->is_owner && $r->user()->active, 403, 'This area is only for the HRMS owner.');

        return $next($r);
    }
}
