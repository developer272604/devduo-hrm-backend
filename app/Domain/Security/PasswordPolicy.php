<?php

namespace App\Domain\Security;

use App\Domain\Audit\Audit;
use App\Domain\Security\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Tenant password rules: length, history, expiry and failed-login lockout. Methods that touch history need tenant context. */
final class PasswordPolicy
{
    public function rule(int $tenantId): Password
    {
        return Password::min(SecurityPolicy::for($tenantId)['password']['min_length'])->letters()->numbers();
    }

    /** Throws a validation error when the candidate password violates length or history rules. */
    public function assertAcceptable(User $user, string $plain): void
    {
        $policy = SecurityPolicy::for($user->tenant_id)['password'];
        if (mb_strlen($plain) < $policy['min_length'] || ! preg_match('/[A-Za-z]/', $plain) || ! preg_match('/\d/', $plain)) {
            throw ValidationException::withMessages(['password' => 'The password must be at least '.$policy['min_length'].' characters with letters and numbers.']);
        }
        if ($policy['history'] > 0 && $this->reused($user, $plain, $policy['history'])) {
            throw ValidationException::withMessages(['password' => 'Choose a password you have not used in your last '.$policy['history'].' passwords.']);
        }
    }

    private function reused(User $user, string $plain, int $depth): bool
    {
        if (Hash::check($plain, $user->password)) {
            return true;
        }
        foreach (PasswordHistory::where('user_id', $user->id)->orderByDesc('id')->limit($depth)->pluck('password_hash') as $hash) {
            if (Hash::check($plain, $hash)) {
                return true;
            }
        }

        return false;
    }

    /** Call after the new password was saved: remembers the previous hash and clears lockout state. */
    public function recordChange(User $user, string $previousHash): void
    {
        PasswordHistory::create(['user_id' => $user->id, 'password_hash' => $previousHash]);
        $keep = PasswordHistory::where('user_id', $user->id)->orderByDesc('id')->pluck('id')->slice(24);
        $keep->isNotEmpty() && PasswordHistory::whereIn('id', $keep)->delete();
        $user->forceFill(['password_changed_at' => now(), 'failed_logins' => 0, 'locked_until' => null])->save();
    }

    public function isExpired(User $user): bool
    {
        $days = SecurityPolicy::for($user->tenant_id)['password']['expiry_days'];

        return $days > 0 && ($user->password_changed_at ?? $user->created_at)?->lt(now()->subDays($days));
    }

    public function isLocked(User $user): bool
    {
        return $user->locked_until && $user->locked_until->isFuture();
    }

    /** Count a failed password attempt; locks the account once the tenant threshold is reached. */
    public function recordFailure(User $user): void
    {
        $policy = SecurityPolicy::for($user->tenant_id)['password'];
        if ($policy['lockout_attempts'] < 1) {
            return;
        }
        $user->forceFill(['failed_logins' => $user->failed_logins + 1]);
        if ($user->failed_logins >= $policy['lockout_attempts']) {
            $user->forceFill(['locked_until' => now()->addMinutes($policy['lockout_minutes']), 'failed_logins' => 0]);
            Audit::record('security.account_locked', 'security', 'user:'.$user->id, ['minutes' => $policy['lockout_minutes']]);
        }
        $user->save();
    }

    public function clearFailures(User $user): void
    {
        if ($user->failed_logins || $user->locked_until) {
            $user->forceFill(['failed_logins' => 0, 'locked_until' => null])->save();
        }
    }
}
