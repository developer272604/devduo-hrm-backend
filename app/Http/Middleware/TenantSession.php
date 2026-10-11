<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantSession
{
    public function handle(Request $r, Closure $next)
    {
        $u = $r->user();
        if (Auth::viaRemember() && ! $r->session()->has('version')) {
            $r->session()->put('version', $u->session_version);
        }
        if (! $u->active || ! $u->tenant_id || $r->session()->get('version') !== $u->session_version) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();

            return response()->json(['message' => 'Your session has expired. Please sign in again.'], 401);
        }
        app(TenantContext::class)->id = $u->tenant_id;
        try {
            return $next($r);
        } finally {
            app(TenantContext::class)->id = null;
        }
    }
}
