<?php

namespace App\Domain\Integrations;

use App\Domain\Audit\Audit;
use App\Domain\Integrations\Models\Connection;
use App\Domain\Integrations\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class IntegrationService
{
    public const PROVIDERS = [
        'google_workspace' => ['label' => 'Google Workspace', 'category' => 'identity', 'live' => false],
        'microsoft_365' => ['label' => 'Microsoft 365', 'category' => 'identity', 'live' => false],
        'generic_webhook' => ['label' => 'Generic webhook', 'category' => 'events', 'live' => false],
        'accounting_export' => ['label' => 'Accounting export', 'category' => 'finance', 'live' => false],
    ];

    public function providers(): array
    {
        return collect(self::PROVIDERS)->map(fn ($provider, $key) => ['key' => $key] + $provider)->values()->all();
    }

    public function create(User $actor, array $input): Connection
    {
        $data = validator($input, [
            'provider_key' => 'required|in:'.implode(',', array_keys(self::PROVIDERS)),
            'name' => 'required|string|max:150',
            'credential_ref' => ['nullable', 'string', 'max:220', 'regex:/^(vault|secret):\/\/[A-Za-z0-9._\/-]{1,180}$/'],
            'scopes' => 'sometimes|array|max:50', 'scopes.*' => 'required|string|max:100',
        ])->validate();
        $connection = Connection::create([
            'provider_key' => $data['provider_key'], 'name' => $data['name'], 'status' => 'disabled',
            'credential_ref' => $data['credential_ref'] ?? null, 'scopes' => $data['scopes'] ?? [],
            'health_status' => 'not_configured', 'created_by' => $actor->id,
        ]);
        Audit::record('integration.connection.created', 'integrations', 'connection:'.$connection->uuid, ['provider' => $connection->provider_key]);

        return $connection;
    }

    public function connections(): array
    {
        return Connection::latest('id')->paginate(25)->through(fn ($c) => $this->present($c))->toArray();
    }

    public function test(User $actor, string $uuid): array
    {
        return DB::transaction(function () use ($actor, $uuid) {
            $connection = Connection::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            abort_unless($connection->status !== 'revoked', 409, 'This integration connection has been revoked.');
            // No provider adapter is live in this lean foundation; never make a network call or imply connectivity.
            $run = SyncRun::create(['connection_id' => $connection->id, 'status' => 'not_configured', 'direction' => 'diagnostic',
                'attempts' => 0, 'summary' => ['live' => false, 'message' => 'No provider adapter is installed.'],
                'error_code' => 'adapter_not_configured', 'started_by' => $actor->id, 'started_at' => now(), 'finished_at' => now()]);
            $connection->update(['health_status' => 'not_configured', 'last_checked_at' => now()]);
            Audit::record('integration.connection.tested', 'integrations', 'connection:'.$connection->uuid, ['result' => 'not_configured']);

            return ['connection' => $this->present($connection->refresh()), 'run' => $run->only(['uuid', 'status', 'direction', 'summary', 'error_code', 'started_at', 'finished_at'])];
        });
    }

    public function runs(string $uuid): array
    {
        $connection = Connection::where('uuid', $uuid)->firstOrFail();
        return SyncRun::where('connection_id', $connection->id)->latest('id')->paginate(25)
            ->through(fn ($run) => $run->only(['uuid', 'status', 'direction', 'attempts', 'summary', 'error_code', 'started_at', 'finished_at']))->toArray();
    }

    public function revoke(User $actor, string $uuid): array
    {
        return DB::transaction(function () use ($actor, $uuid) {
            $connection = Connection::where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($connection->status !== 'revoked') {
                $connection->update(['status' => 'revoked', 'health_status' => 'revoked', 'credential_ref' => null, 'scopes' => []]);
                Audit::record('integration.connection.revoked', 'integrations', 'connection:'.$connection->uuid);
            }

            return $this->present($connection->refresh());
        });
    }

    private function present(Connection $connection): array
    {
        return $connection->only(['uuid', 'provider_key', 'name', 'status', 'scopes', 'health_status', 'last_checked_at', 'created_at']);
    }
}
