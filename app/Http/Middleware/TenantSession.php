<?php

namespace App\Http\Middleware;

use App\Domain\Security\MfaService;
use App\Domain\Security\PasswordPolicy;
use App\Domain\Security\SessionService;
use App\Domain\Tenancy\AccountLifecycle;
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

    private function lockedMessage(array $s): string
    {
        return match (true) {
            $s['status'] === 'suspended' && $s['reason'] === 'trial_expired' => 'Your 24-hour free trial has ended. Your account is suspended until the HRMS owner approves it.',
            $s['status'] === 'rejected' => 'Your account request was not approved.',
            $s['status'] === 'deactivated' => 'Your account has been deactivated. Contact the HRMS owner.',
            default => 'Your account is suspended. Contact the HRMS owner.',
        };
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
        // Trial over, awaiting owner approval, rejected or deactivated: only the status screen and sign-out stay available.
        if (! $u->is_owner && ! $r->is('api/me', 'api/logout') && ! app(AccountLifecycle::class)->hasAccess($u->tenant)) {
            $s = app(AccountLifecycle::class)->summary($u->tenant);

            return response()->json(['message' => $this->lockedMessage($s), 'code' => 'account_'.$s['status'], 'account' => $s], 403);
        }
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
