<?php

namespace App\Domain\Security;

use App\Domain\Audit\Audit;
use App\Domain\Security\Models\UserSession;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Server-side register of signed-in sessions. Only a SHA-256 of the session id is stored. Revocation, idle/absolute
 * timeouts and the concurrent-session limit are enforced by the tenant middleware on the next request.
 */
final class SessionService
{
    public static function hashId(string $id): string
    {
        return hash('sha256', $id);
    }

    /** Called right after login (session id already regenerated). */
    public function start(User $user, Request $request): UserSession
    {
        $session = $this->create($user, $request);
        $this->enforceLimit($user, $session);

        return $session;
    }

    /** Update the register for this request. Returns a rejection reason when the session must be terminated. */
    public function touch(User $user, Request $request): ?string
    {
        $hash = self::hashId($request->session()->getId());
        $session = UserSession::where('user_id', $user->id)->where('session_hash', $hash)->first() ?? $this->create($user, $request);
        if ($session->revoked_at) {
            return 'revoked';
        }
        $policy = SecurityPolicy::for($user->tenant_id)['session'];
        $now = now();
        if ($policy['idle_minutes'] > 0 && $session->last_seen_at && $session->last_seen_at->lt($now->copy()->subMinutes($policy['idle_minutes']))) {
            $this->revokeModel($session, 'idle_timeout');

            return 'idle_timeout';
        }
        if ($policy['absolute_minutes'] > 0 && $session->created_at->lt($now->copy()->subMinutes($policy['absolute_minutes']))) {
            $this->revokeModel($session, 'absolute_timeout');

            return 'absolute_timeout';
        }
        if (! $session->last_seen_at || $session->last_seen_at->lt($now->copy()->subSeconds(60))) {
            $session->forceFill(['last_seen_at' => $now])->save();
        }

        return null;
    }

    /** Called after Laravel regenerates the id of a live session (password change). */
    public function rotate(User $user, string $oldId, string $newId): void
    {
        UserSession::where('user_id', $user->id)->where('session_hash', self::hashId($oldId))->update(['session_hash' => self::hashId($newId)]);
    }

    public function list(User $user, string $currentId): array
    {
        return $this->active($user->id)->orderByDesc('last_seen_at')->get()->map(fn (UserSession $s) => [
            'uuid' => $s->uuid, 'device' => $s->device, 'ip_address' => $s->ip_address, 'signed_in_at' => $s->created_at->toIso8601String(),
            'last_seen_at' => $s->last_seen_at?->toIso8601String(), 'current' => hash_equals($s->session_hash, self::hashId($currentId)),
        ])->all();
    }

    public function revoke(User $user, string $uuid, string $currentId): void
    {
        $session = UserSession::where('user_id', $user->id)->where('uuid', $uuid)->whereNull('revoked_at')->firstOrFail();
        abort_if(hash_equals($session->session_hash, self::hashId($currentId)), 422, 'Use sign out to end your current session.');
        $this->revokeModel($session, 'user_revoked');
        Audit::record('security.session.revoked', 'security', 'session:'.$session->uuid, ['user_id' => $user->id]);
    }

    public function revokeOthers(User $user, string $currentId, string $reason = 'user_revoked_others'): int
    {
        $count = UserSession::where('user_id', $user->id)->whereNull('revoked_at')->where('session_hash', '!=', self::hashId($currentId))
            ->update(['revoked_at' => now(), 'revoke_reason' => $reason]);
        $count && Audit::record('security.session.revoked_others', 'security', 'user:'.$user->id, ['count' => $count, 'reason' => $reason]);

        return $count;
    }

    /** Forced logout of every session of a user (admin action, password reset, MFA reset). */
    public function revokeAllFor(int $userId, string $reason, ?string $exceptId = null): int
    {
        $query = UserSession::where('user_id', $userId)->whereNull('revoked_at');
        if ($exceptId) {
            $query->where('session_hash', '!=', self::hashId($exceptId));
        }

        return $query->update(['revoked_at' => now(), 'revoke_reason' => $reason]);
    }

    private function create(User $user, Request $request): UserSession
    {
        $agent = (string) $request->userAgent();

        return UserSession::create(['user_id' => $user->id, 'session_hash' => self::hashId($request->session()->getId()), 'ip_address' => $request->ip(),
            'user_agent' => mb_substr($agent, 0, 255), 'device' => $this->device($agent), 'last_seen_at' => now()]);
    }

    private function active(int $userId)
    {
        $policy = SecurityPolicy::for(app(\App\Domain\Tenancy\TenantContext::class)->id)['session'];
        $horizon = $policy['idle_minutes'] > 0 ? $policy['idle_minutes'] : (int) config('session.lifetime', 120);
        $query = UserSession::where('user_id', $userId)->whereNull('revoked_at')->where('last_seen_at', '>=', now()->subMinutes($horizon));
        if ($policy['absolute_minutes'] > 0) {
            $query->where('created_at', '>=', now()->subMinutes($policy['absolute_minutes']));
        }

        return $query;
    }

    /** Keep only the newest `max_concurrent` sessions; older ones are signed out on their next request. */
    private function enforceLimit(User $user, UserSession $current): void
    {
        $max = SecurityPolicy::for($user->tenant_id)['session']['max_concurrent'];
        if ($max < 1) {
            return;
        }
        $others = $this->active($user->id)->where('id', '!=', $current->id)->orderByDesc('last_seen_at')->orderByDesc('id')->get();
        foreach ($others->slice($max - 1) as $old) {
            $this->revokeModel($old, 'concurrent_limit');
            Audit::record('security.session.revoked_limit', 'security', 'session:'.$old->uuid, ['user_id' => $user->id, 'limit' => $max]);
        }
    }

    private function revokeModel(UserSession $session, string $reason): void
    {
        $session->forceFill(['revoked_at' => now(), 'revoke_reason' => $reason])->save();
    }

    private function device(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };
        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        return $browser.' on '.$os;
    }
}
