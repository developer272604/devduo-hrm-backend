<?php

namespace App\Domain\Integrations\Api;

use App\Domain\Audit\Audit;
use App\Domain\Integrations\Models\ApiClient;
use App\Domain\Integrations\Models\ApiRequest;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tenant API clients. Keys look like "dvk_<8-char prefix>.<40-char secret>"; the plaintext is returned once (create/rotate)
 * and only its SHA-256 hash is stored. Authentication is constant-time and never reveals why a key was rejected.
 */
final class ApiClientService
{
    public const PREFIX = 'dvk_';

    /** scope => description. Scopes are least-privilege and read-only in v1. */
    public const SCOPES = [
        'employees:read' => 'Read minimal employee directory fields',
        'employees:email' => 'Include work email in employee records',
        'departments:read' => 'Read departments',
    ];

    public function list(): array
    {
        return ApiClient::query()->latest('id')->get()->map(fn ($c) => $this->present($c))->all();
    }

    /** @return array{client:array,key:string} */
    public function create(User $actor, array $input): array
    {
        $data = $this->validated($input, true);
        [$token, $prefix] = $this->newToken();
        $client = ApiClient::create([
            'name' => $data['name'], 'key_prefix' => $prefix, 'key_hash' => hash('sha256', $token), 'scopes' => array_values(array_unique($data['scopes'])),
            'rate_limit_per_minute' => $data['rate_limit_per_minute'] ?? 60, 'ip_allowlist' => $data['ip_allowlist'] ?? [], 'status' => 'active',
            'expires_at' => $data['expires_at'] ?? null, 'created_by' => $actor->id,
        ]);
        Audit::record('integration.api_client.created', 'integrations', 'api_client:'.$client->uuid, ['scopes' => $client->scopes]);

        return ['client' => $this->present($client), 'key' => $token];
    }

    public function update(User $actor, string $uuid, array $input): array
    {
        $client = ApiClient::byUuid($uuid);
        abort_if($client->status === 'revoked', 409, 'This API client has been revoked.');
        $data = $this->validated($input, false);
        $client->update(array_intersect_key($data, array_flip(['name', 'scopes', 'rate_limit_per_minute', 'ip_allowlist', 'expires_at'])));
        Audit::record('integration.api_client.updated', 'integrations', 'api_client:'.$client->uuid, ['fields' => array_keys($data)]);

        return $this->present($client->refresh());
    }

    /** Issues a new key. The old key keeps working for $graceMinutes (0 = immediately invalid). @return array{client:array,key:string} */
    public function rotate(User $actor, string $uuid, int $graceMinutes = 0): array
    {
        $graceMinutes = max(0, min(1440, $graceMinutes));

        return DB::transaction(function () use ($actor, $uuid, $graceMinutes) {
            $client = ApiClient::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_if($client->status === 'revoked', 409, 'This API client has been revoked.');
            [$token, $prefix] = $this->newToken();
            $client->forceFill([
                'previous_key_hash' => $graceMinutes ? $client->key_hash : null, 'previous_valid_until' => $graceMinutes ? now()->addMinutes($graceMinutes) : null,
                'key_hash' => hash('sha256', $token), 'key_prefix' => $prefix, 'rotated_at' => now(),
            ])->save();
            Audit::record('integration.api_client.rotated', 'integrations', 'api_client:'.$client->uuid, ['grace_minutes' => $graceMinutes]);

            return ['client' => $this->present($client->refresh()), 'key' => $token];
        });
    }

    public function revoke(User $actor, string $uuid): array
    {
        $client = ApiClient::byUuid($uuid);
        if ($client->status !== 'revoked') {
            $client->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'previous_key_hash' => null, 'previous_valid_until' => null])->save();
            Audit::record('integration.api_client.revoked', 'integrations', 'api_client:'.$client->uuid);
        }

        return $this->present($client->refresh());
    }

    public function requests(string $uuid): array
    {
        $client = ApiClient::byUuid($uuid);

        return ApiRequest::query()->where('client_id', $client->id)->latest('id')->paginate(50)
            ->through(fn ($r) => $r->only(['method', 'path', 'status', 'ip', 'created_at']))->toArray();
    }

    /**
     * Resolves a presented key to an active client in ANY tenant (the tenant is derived from the client, never from the request).
     * Returns null for unknown, revoked and expired keys alike.
     */
    public function authenticate(?string $token): ?ApiClient
    {
        if (! is_string($token) || ! preg_match('/^'.self::PREFIX.'([a-z0-9]{8})\.[A-Za-z0-9]{40}$/', $token, $m)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $client = ApiClient::withoutGlobalScopes()->where('key_prefix', $m[1])->first();
        $matched = $client && hash_equals($client->key_hash, $hash);
        if (! $matched) {
            // A rotated key stays valid during its grace window; its prefix is no longer unique, so look it up by hash.
            $client = ApiClient::withoutGlobalScopes()->where('previous_key_hash', $hash)->where('previous_valid_until', '>', now())->first();
            $matched = $client !== null;
        }
        if (! $matched || $client->status !== 'active' || ($client->expires_at && $client->expires_at->isPast())) {
            return null;
        }

        return $client;
    }

    public static function ipAllowed(ApiClient $client, ?string $ip): bool
    {
        $list = $client->ip_allowlist ?: [];
        if (! $list) {
            return true;
        }
        foreach ($list as $rule) {
            if ($ip && self::ipMatches($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    public static function ipMatches(string $ip, string $rule): bool
    {
        if (! str_contains($rule, '/')) {
            return strcasecmp(inet_ntop(@inet_pton($ip) ?: '') ?: $ip, inet_ntop(@inet_pton($rule) ?: '') ?: $rule) === 0;
        }
        [$subnet, $bits] = explode('/', $rule, 2);
        $a = @inet_pton($ip);
        $b = @inet_pton($subnet);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        if (strncmp($a, $b, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
    }

    /** Fixed one-minute window per client. @return array{allowed:bool,limit:int,remaining:int,reset:int} */
    public function hit(ApiClient $client): array
    {
        $window = intdiv(time(), 60);
        $key = 'intapi:rl:'.$client->id.':'.$window;
        Cache::add($key, 0, 120);
        $count = (int) Cache::increment($key);
        $limit = max(1, (int) $client->rate_limit_per_minute);

        return ['allowed' => $count <= $limit, 'limit' => $limit, 'remaining' => max(0, $limit - $count), 'reset' => ($window + 1) * 60 - time()];
    }

    public function audit(ApiClient $client, string $method, string $path, int $status, ?string $ip): void
    {
        ApiRequest::create(['client_id' => $client->id, 'method' => $method, 'path' => mb_substr($path, 0, 200), 'status' => $status, 'ip' => $ip, 'created_at' => now()]);
    }

    public function present(ApiClient $c): array
    {
        return ['uuid' => $c->uuid, 'name' => $c->name, 'key_prefix' => self::PREFIX.$c->key_prefix, 'scopes' => $c->scopes, 'rate_limit_per_minute' => $c->rate_limit_per_minute,
            'ip_allowlist' => $c->ip_allowlist ?: [], 'status' => $c->status, 'expires_at' => $c->expires_at?->toIso8601String(), 'last_used_at' => $c->last_used_at?->toIso8601String(),
            'rotated_at' => $c->rotated_at?->toIso8601String(), 'revoked_at' => $c->revoked_at?->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String()];
    }

    private function validated(array $input, bool $creating): array
    {
        $data = validator($input, [
            'name' => ($creating ? 'required' : 'sometimes').'|string|max:150',
            'scopes' => ($creating ? 'required' : 'sometimes').'|array|min:1|max:20', 'scopes.*' => 'required|string|in:'.implode(',', array_keys(self::SCOPES)),
            'rate_limit_per_minute' => 'sometimes|integer|min:1|max:6000',
            'ip_allowlist' => 'sometimes|array|max:50', 'ip_allowlist.*' => 'required|string|max:64',
            'expires_at' => 'sometimes|nullable|date|after:now',
        ])->validate();
        foreach ($data['ip_allowlist'] ?? [] as $i => $rule) {
            if (! $this->validRule($rule)) {
                throw ValidationException::withMessages(["ip_allowlist.$i" => ['Use an IP address or CIDR range such as 203.0.113.0/24.']]);
            }
        }

        return $data;
    }

    private function validRule(string $rule): bool
    {
        [$ip, $bits] = array_pad(explode('/', $rule, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if ($bits === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($bits) && (int) $bits <= $max;
    }

    /** @return array{0:string,1:string} [token, prefix] */
    private function newToken(): array
    {
        do {
            $prefix = Str::lower(Str::random(8));
        } while (ApiClient::withoutGlobalScopes()->where('key_prefix', $prefix)->exists());

        return [self::PREFIX.$prefix.'.'.Str::random(40), $prefix];
    }
}
