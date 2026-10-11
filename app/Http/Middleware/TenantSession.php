<?php

namespace App\Http\Middleware;

use App\Domain\Security\MfaService;
use App\Domain\Security\PasswordPolicy;
use App\Domain\Security\SessionService;
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
            return $this->endSession($r, 'Your session has expired. Please sign in again.');
        }
        app(TenantContext::class)->id = $u->tenant_id;
        try {
            // Server-side session register: revocation, idle/absolute timeout and concurrent-session limit.
            if ($reason = app(SessionService::class)->touch($u, $r)) {
                return $this->endSession($r, 'Your session has ended ('.str_replace('_', ' ', $reason).'). Please sign in again.');
            }
            if ($blocked = $this->gate($r, $u)) {
                return $blocked;
            }

            return $next($r);
        } finally {
            app(TenantContext::class)->id = null;
        }
    }

    private function endSession(Request $r, string $message)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->json(['message' => $message], 401);
    }

    /** Enforced MFA enrolment and expired passwords restrict the account to the self-service routes needed to fix them. */
    private function gate(Request $r, $u)
    {
        $always = $r->is('api/me', 'api/logout', 'api/change-password');
        if (! $always && app(PasswordPolicy::class)->isExpired($u)) {
            return response()->json(['message' => 'Your password has expired. Change it to continue.', 'code' => 'password_expired'], 403);
        }
        $mfa = app(MfaService::class);
        if (! $always && ! $r->is('api/v1/security/mfa', 'api/v1/security/mfa/*') && $mfa->isRequired($u) && ! $mfa->isEnrolled($u)) {
            return response()->json(['message' => 'Your workspace requires multi-factor authentication. Set it up to continue.', 'code' => 'mfa_enrollment_required'], 403);
        }

        return null;
    }
}
