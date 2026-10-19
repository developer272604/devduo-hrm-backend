<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
use App\Domain\Security\MfaService;
use App\Domain\Security\PasswordPolicy;
use App\Domain\Security\SessionService;
use App\Domain\Access\WorkspaceProvisioner;
use App\Domain\Tenancy\AccountLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    private const MFA_WINDOW_SECONDS = 300;

    private const MFA_MAX_ATTEMPTS = 5;

    public function csrf()
    {
        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function login(Request $r)
    {
        $data = $r->validate(['workspace' => 'required|string|max:100', 'email' => 'required|email|max:255', 'password' => 'required|string|max:255', 'remember' => 'boolean']);
        $key = 'login:'.hash('sha256', strtolower($data['workspace'].'|'.$data['email']));
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.'], 429);
        }
        $tenant = Tenant::where('slug', $data['workspace'])->first();
        $u = User::where('tenant_id', $tenant?->id ?? -1)->where('email', strtolower($data['email']))->first();
        $policy = app(PasswordPolicy::class);
        $context = app(TenantContext::class);
        if ($u) {
            $context->id = $u->tenant_id;
        }
        try {
            if ($u && $u->active && $policy->isLocked($u)) {
                return response()->json(['message' => 'Too many attempts. Try again later or reset your password.'], 429);
            }
            if (! $u || ! $u->active || ! Hash::check($data['password'], $u->password)) {
                RateLimiter::hit($key, 300);
                $u && $u->active && $policy->recordFailure($u);

                return response()->json(['message' => 'The workspace, email, or password is incorrect.'], 422);
            }
            RateLimiter::clear($key);
            $policy->clearFailures($u);
            if (app(MfaService::class)->isEnrolled($u)) {
                // Password accepted, second factor outstanding: nothing is authenticated yet.
                $r->session()->regenerate();
                $r->session()->put('mfa_pending', ['user_id' => $u->id, 'remember' => (bool) ($data['remember'] ?? false), 'expires' => now()->addSeconds(self::MFA_WINDOW_SECONDS)->timestamp, 'attempts' => 0]);

                return response()->json(['mfa_required' => true, 'expires_in' => self::MFA_WINDOW_SECONDS]);
            }

            return $this->complete($r, $u, $data['remember'] ?? false);
        } finally {
            $context->id = null;
        }
    }

    /** Self-service sign-up: creates a workspace, its administrator, and starts the 24-hour free trial. */
    public function register(Request $r)
    {
        $data = $r->validate([
            'company_name' => 'required|string|max:120',
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:255', function ($attr, $value, $fail) {
                if (User::where('email', strtolower($value))->exists()) {
                    $fail('An account with this email already exists.');
                }
            }],
            'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()],
        ]);
        $base = Str::slug($data['company_name']) ?: 'company';
        if ($base === 'platform') {
            $base = 'platform-company';
        }
        $slug = $base;
        while (Tenant::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(4));
        }
        $u = app(WorkspaceProvisioner::class)->create($data['company_name'], $slug, $data['email'], $data['password']);
        $u->forceFill(['name' => $data['name']])->save();
        app(AccountLifecycle::class)->startTrial($u->tenant);
        $context = app(TenantContext::class);
        $context->id = $u->tenant_id;
        try {
            return $this->complete($r, $u, false);
        } finally {
            $context->id = null;
        }
    }

    /** Second login step: TOTP code or a single-use recovery code. */
    public function loginMfa(Request $r)
    {
        $data = $r->validate(['code' => 'required|string|max:32']);
        $pending = $r->session()->get('mfa_pending');
        if (! $pending || $pending['expires'] < now()->timestamp) {
            $r->session()->forget('mfa_pending');

            return response()->json(['message' => 'Your sign-in timed out. Sign in again.', 'code' => 'mfa_expired'], 422);
        }
        $u = User::find($pending['user_id']);
        if (! $u || ! $u->active) {
            $r->session()->forget('mfa_pending');

            return response()->json(['message' => 'Your sign-in timed out. Sign in again.', 'code' => 'mfa_expired'], 422);
        }
        $context = app(TenantContext::class);
        $context->id = $u->tenant_id;
        try {
            if (! app(MfaService::class)->verifyChallenge($u, $data['code'])) {
                $pending['attempts']++;
                if ($pending['attempts'] >= self::MFA_MAX_ATTEMPTS) {
                    $r->session()->forget('mfa_pending');

                    return response()->json(['message' => 'Too many incorrect codes. Sign in again.', 'code' => 'mfa_expired'], 422);
                }
                $r->session()->put('mfa_pending', $pending);

                return response()->json(['message' => 'That code is not valid.', 'errors' => ['code' => ['That code is not valid.']]], 422);
            }
            $r->session()->forget('mfa_pending');

            return $this->complete($r, $u, $pending['remember']);
        } finally {
            $context->id = null;
        }
    }

    private function complete(Request $r, User $u, bool $remember)
    {
        Auth::login($u, $remember);
        $r->session()->regenerate();
        $r->session()->put('version', $u->session_version);
        app(SessionService::class)->start($u, $r);
        Audit::record('login', 'security', 'user:'.$u->id);

        return $this->me($r);
    }

    public function me(Request $r)
    {
        $u = $r->user();
        $tenant = $u->tenant;
        $mfa = app(MfaService::class);
        $security = ['mfa_enabled' => $mfa->isEnrolled($u), 'mfa_enrollment_required' => $mfa->isRequired($u) && ! $mfa->isEnrolled($u), 'password_expired' => app(PasswordPolicy::class)->isExpired($u)];

        return response()->json(['user' => $u, 'roles' => $u->roles()->get(['roles.id', 'name', 'data_scope']), 'permissions' => $u->permissionKeys(), 'security' => $security,
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'setup_completed_at' => $tenant->setup_completed_at, 'settings' => $tenant->settings, 'is_platform' => $tenant->is_platform],
            'account' => app(AccountLifecycle::class)->summary($tenant) + ['is_owner' => (bool) $u->is_owner]]);
    }

    public function logout(Request $r)
    {
        Audit::record('logout', 'security', 'user:'.$r->user()->id);
        \App\Domain\Security\Models\UserSession::where('user_id', $r->user()->id)->where('session_hash', SessionService::hashId($r->session()->getId()))->update(['revoked_at' => now(), 'revoke_reason' => 'logout']);
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->noContent();
    }

    public function forgot(Request $r)
    {
        $r->validate(['email' => 'required|email|max:255']);
        Password::sendResetLink(['email' => strtolower($r->email), 'active' => true]);

        return response()->json(['message' => 'If an active account exists, a password reset link has been sent.']);
    }

    public function reset(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()]]);
        $status = Password::reset($data, function (User $u, string $password) {
            app(TenantContext::class)->id = $u->tenant_id;
            try {
                $policy = app(PasswordPolicy::class);
                $policy->assertAcceptable($u, $password); // length + history per tenant policy; the reset token stays valid on failure
                DB::transaction(function () use ($u, $password, $policy) {
                    $previous = $u->password;
                    $u->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'session_version' => $u->session_version + 1])->save();
                    $policy->recordChange($u, $previous);
                    app(SessionService::class)->revokeAllFor($u->id, 'password_reset');
                    Audit::record('password_reset', 'security', 'user:'.$u->id);
                });
            } finally {
                app(TenantContext::class)->id = null;
            }
        });

        return response()->json(['message' => __($status)], $status === Password::PASSWORD_RESET ? 200 : 422);
    }

    public function change(Request $r)
    {
        $min = app(PasswordPolicy::class)->rule($r->user()->tenant_id);
        $data = $r->validate(['current_password' => 'required|current_password:web', 'password' => ['required', 'confirmed', 'different:current_password', $min]]);
        $policy = app(PasswordPolicy::class);
        $policy->assertAcceptable($r->user(), $data['password']);
        $oldSessionId = $r->session()->getId();
        DB::transaction(function () use ($r, $data, $policy) {
            $u = $r->user();
            $previous = $u->password;
            $u->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60), 'session_version' => $u->session_version + 1])->save();
            $policy->recordChange($u, $previous);
            Audit::record('password_changed', 'security', 'user:'.$u->id);
            $r->session()->put('version', $u->session_version);
        });
        $r->session()->regenerate();
        $sessions = app(SessionService::class);
        $sessions->rotate($r->user(), $oldSessionId, $r->session()->getId());
        $sessions->revokeAllFor($r->user()->id, 'password_changed', $r->session()->getId());

        return response()->json(['message' => 'Password updated. Other sessions have been signed out.']);
    }
}
