<?php

namespace App\Domain\Security;

use App\Domain\Audit\Audit;
use App\Domain\Security\Models\MfaFactor;
use App\Domain\Security\Models\MfaRecoveryCode;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** TOTP enrolment, challenge verification and single-use recovery codes. Requires tenant context. */
final class MfaService
{
    private const CODES = 10;

    private const MAX_ATTEMPTS = 5;

    public function factor(User $user): ?MfaFactor
    {
        return MfaFactor::where('user_id', $user->id)->first();
    }

    public function isEnrolled(User $user): bool
    {
        return (bool) $this->factor($user)?->confirmed_at;
    }

    /** Is MFA mandatory for this user under the tenant policy? */
    public function isRequired(User $user): bool
    {
        $mfa = SecurityPolicy::for($user->tenant_id)['mfa'];
        if ($mfa['enforcement'] === 'all') {
            return true;
        }

        return $mfa['enforcement'] === 'roles' && $mfa['role_ids'] && $user->roles()->whereIn('roles.id', $mfa['role_ids'])->exists();
    }

    public function status(User $user): array
    {
        $factor = $this->factor($user);

        return ['enabled' => (bool) $factor?->confirmed_at, 'pending_enrollment' => $factor && ! $factor->confirmed_at, 'required' => $this->isRequired($user),
            'recovery_codes_remaining' => $factor?->confirmed_at ? MfaRecoveryCode::where('user_id', $user->id)->whereNull('used_at')->count() : 0];
    }

    public function beginEnrollment(User $user): array
    {
        abort_if($this->isEnrolled($user), 409, 'Multi-factor authentication is already enabled.');
        $secret = Totp::generateSecret();
        MfaFactor::updateOrCreate(['user_id' => $user->id], ['secret' => Crypt::encryptString($secret), 'confirmed_at' => null, 'last_used_step' => null]);
        Audit::record('security.mfa.enrollment_started', 'security', 'user:'.$user->id);
        $issuer = $user->tenant->name ?: 'devduo HRM';

        return ['secret' => $secret, 'otpauth_uri' => Totp::uri($issuer, $user->email, $secret), 'issuer' => $issuer, 'account' => $user->email, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => 30];
    }

    /** @return list<string> plaintext recovery codes, shown exactly once */
    public function confirm(User $user, string $code): array
    {
        $factor = $this->factor($user);
        abort_unless($factor && ! $factor->confirmed_at, 409, 'Start enrolment first.');
        $this->throttle($user);
        $step = Totp::verify(Crypt::decryptString($factor->secret), $code);
        if ($step === null) {
            $this->fail($user, 'security.mfa.enrollment_failed');
        }
        RateLimiter::clear($this->key($user));

        return DB::transaction(function () use ($factor, $user, $step) {
            $factor->update(['confirmed_at' => now(), 'last_used_step' => $step]);
            $codes = $this->issueRecoveryCodes($user);
            Audit::record('security.mfa.enabled', 'security', 'user:'.$user->id);

            return $codes;
        });
    }

    public function regenerateRecoveryCodes(User $user, string $code): array
    {
        abort_unless($this->isEnrolled($user), 409, 'Multi-factor authentication is not enabled.');
        abort_unless($this->verifyTotpOnly($user, $code), 422, 'That code is not valid.');

        return DB::transaction(function () use ($user) {
            $codes = $this->issueRecoveryCodes($user);
            Audit::record('security.mfa.recovery_codes_regenerated', 'security', 'user:'.$user->id);

            return $codes;
        });
    }

    public function disable(User $user, string $password, string $code): void
    {
        abort_unless($this->isEnrolled($user), 409, 'Multi-factor authentication is not enabled.');
        abort_if($this->isRequired($user), 403, 'Your workspace requires multi-factor authentication for your account.');
        if (! \Illuminate\Support\Facades\Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }
        abort_unless($this->verifyChallenge($user, $code), 422, 'That code is not valid.');
        $this->remove($user);
        Audit::record('security.mfa.disabled', 'security', 'user:'.$user->id);
    }

    /** Admin reset (lost device). The user must re-enrol; their sessions are signed out. */
    public function adminReset(User $actor, User $target): void
    {
        $this->remove($target);
        app(SessionService::class)->revokeAllFor($target->id, 'mfa_reset');
        Audit::record('security.mfa.reset_by_admin', 'security', 'user:'.$target->id, ['actor_id' => $actor->id]);
    }

    /** Login challenge: TOTP (replay protected) or a single-use recovery code. */
    public function verifyChallenge(User $user, string $code): bool
    {
        $this->throttle($user);
        $factor = $this->factor($user);
        if (! $factor?->confirmed_at) {
            return false;
        }
        $step = Totp::verify(Crypt::decryptString($factor->secret), $code, afterStep: $factor->last_used_step);
        if ($step !== null) {
            // Compare-and-set so one TOTP step can never be accepted twice, even concurrently.
            $claimed = MfaFactor::whereKey($factor->id)->where(fn ($q) => $q->whereNull('last_used_step')->orWhere('last_used_step', '<', $step))->update(['last_used_step' => $step]);
            if ($claimed) {
                RateLimiter::clear($this->key($user));

                return true;
            }
        } elseif ($this->consumeRecoveryCode($user, $code)) {
            RateLimiter::clear($this->key($user));
            Audit::record('security.mfa.recovery_code_used', 'security', 'user:'.$user->id);

            return true;
        }
        RateLimiter::hit($this->key($user), 300);
        Audit::record('security.mfa.challenge_failed', 'security', 'user:'.$user->id);

        return false;
    }

    private function verifyTotpOnly(User $user, string $code): bool
    {
        $factor = $this->factor($user);
        $this->throttle($user);
        $step = $factor?->confirmed_at ? Totp::verify(Crypt::decryptString($factor->secret), $code, afterStep: $factor->last_used_step) : null;
        if ($step === null) {
            RateLimiter::hit($this->key($user), 300);

            return false;
        }
        $factor->update(['last_used_step' => $step]);

        return true;
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = $this->normalize($code);
        if (strlen($normalized) !== 10) {
            return false;
        }

        return MfaRecoveryCode::where('user_id', $user->id)->where('code_hash', $this->hash($normalized))->whereNull('used_at')->update(['used_at' => now()]) === 1;
    }

    private function issueRecoveryCodes(User $user): array
    {
        MfaRecoveryCode::where('user_id', $user->id)->delete();
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];
        for ($i = 0; $i < self::CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            MfaRecoveryCode::create(['user_id' => $user->id, 'code_hash' => $this->hash($raw)]);
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        return $codes;
    }

    private function remove(User $user): void
    {
        MfaRecoveryCode::where('user_id', $user->id)->delete();
        MfaFactor::where('user_id', $user->id)->delete();
    }

    private function normalize(string $code): string
    {
        return strtolower(preg_replace('/[\s-]/', '', $code));
    }

    /** Recovery codes carry ~50 bits of entropy, so a keyed fast hash is appropriate; the app key makes a leaked table useless. */
    private function hash(string $normalized): string
    {
        return hash_hmac('sha256', $normalized, 'mfa-recovery|'.config('app.key'));
    }

    private function key(User $user): string
    {
        return 'mfa:'.$user->id;
    }

    private function throttle(User $user): void
    {
        if (RateLimiter::tooManyAttempts($this->key($user), self::MAX_ATTEMPTS)) {
            abort(429, 'Too many incorrect codes. Try again in '.RateLimiter::availableIn($this->key($user)).' seconds.');
        }
    }

    private function fail(User $user, string $audit): never
    {
        RateLimiter::hit($this->key($user), 300);
        Audit::record($audit, 'security', 'user:'.$user->id);
        throw ValidationException::withMessages(['code' => 'That code is not valid.']);
    }
}
