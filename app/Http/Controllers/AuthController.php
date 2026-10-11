<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Audit;
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
        if (! $u || ! $u->active || ! Hash::check($data['password'], $u->password)) {
            RateLimiter::hit($key, 300);

            return response()->json(['message' => 'The workspace, email, or password is incorrect.'], 422);
        }
        RateLimiter::clear($key);
        Auth::login($u, $data['remember'] ?? false);
        $r->session()->regenerate();
        $r->session()->put('version', $u->session_version);
        app(TenantContext::class)->id = $u->tenant_id;
        Audit::record('login', 'security', 'user:'.$u->id);
        $response = $this->me($r);
        app(TenantContext::class)->id = null;

        return $response;
    }

    public function me(Request $r)
    {
        $u = $r->user();
        $tenant = $u->tenant;

        return response()->json(['user' => $u, 'roles' => $u->roles()->get(['roles.id', 'name', 'data_scope']), 'permissions' => $u->permissionKeys(),
            'tenant' => ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'setup_completed_at' => $tenant->setup_completed_at, 'settings' => $tenant->settings]]);
    }

    public function logout(Request $r)
    {
        Audit::record('logout', 'security', 'user:'.$r->user()->id);
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
        $data = $r->validate(['email' => 'required|email', 'token' => 'required|string', 'password' => ['required', 'confirmed', PasswordRule::min(12)->letters()->numbers()]]);
        $status = Password::reset($data, function (User $u, string $password) {
            DB::transaction(function () use ($u, $password) {
                $u->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'session_version' => $u->session_version + 1])->save();
                app(TenantContext::class)->id = $u->tenant_id;
                Audit::record('password_reset', 'security', 'user:'.$u->id);
                app(TenantContext::class)->id = null;
            });
        });

        return response()->json(['message' => __($status)], $status === Password::PASSWORD_RESET ? 200 : 422);
    }

    public function change(Request $r)
    {
        $data = $r->validate(['current_password' => 'required|current_password:web', 'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::min(12)->letters()->numbers()]]);
        DB::transaction(function () use ($r, $data) {
            $u = $r->user();
            $u->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60), 'session_version' => $u->session_version + 1])->save();
            Audit::record('password_changed', 'security', 'user:'.$u->id);
            $r->session()->put('version', $u->session_version);
        });
        $r->session()->regenerate();

        return response()->json(['message' => 'Password updated. Other sessions have been signed out.']);
    }
}
