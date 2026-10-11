<?php

namespace App\Http\Controllers\Integrations;

use App\Domain\Integrations\Api\ApiClientService;
use App\Domain\Integrations\Health\HealthService;
use App\Domain\Integrations\Imports\ImportProfileService;
use App\Domain\Integrations\Webhooks\WebhookService;
use Illuminate\Http\Request;

/** Admin endpoints for the integration platform runtime (API clients, webhooks, import profiles, health). */
final class PlatformController
{
    public function __construct(private ApiClientService $clients, private WebhookService $webhooks, private ImportProfileService $imports, private HealthService $health) {}

    // API clients
    public function clients()
    {
        return ['data' => $this->clients->list(), 'scopes' => collect(ApiClientService::SCOPES)->map(fn ($d, $k) => ['key' => $k, 'description' => $d])->values()];
    }

    public function createClient(Request $r)
    {
        return response()->json($this->clients->create($r->user(), $r->all()), 201);
    }

    public function updateClient(Request $r, string $uuid)
    {
        return $this->clients->update($r->user(), $uuid, $r->all());
    }

    public function rotateClient(Request $r, string $uuid)
    {
        return $this->clients->rotate($r->user(), $uuid, (int) $r->input('grace_minutes', 0));
    }

    public function revokeClient(Request $r, string $uuid)
    {
        return $this->clients->revoke($r->user(), $uuid);
    }

    public function clientRequests(string $uuid)
    {
        return $this->clients->requests($uuid);
    }

    // Webhooks
    public function webhooks()
    {
        return ['data' => $this->webhooks->list(), 'event_types' => WebhookService::eventTypes()];
    }

    public function createWebhook(Request $r)
    {
        return response()->json($this->webhooks->create($r->user(), $r->all()), 201);
    }

    public function updateWebhook(Request $r, string $uuid)
    {
        return $this->webhooks->update($r->user(), $uuid, $r->all());
    }

    public function rotateWebhookSecret(Request $r, string $uuid)
    {
        return $this->webhooks->rotateSecret($r->user(), $uuid);
    }

    public function deleteWebhook(Request $r, string $uuid)
    {
        $this->webhooks->delete($r->user(), $uuid);

        return response()->noContent();
    }

    public function testWebhook(Request $r, string $uuid)
    {
        return response()->json($this->webhooks->test($r->user(), $uuid), 202);
    }

    public function deliveries(Request $r, string $uuid)
    {
        return $this->webhooks->deliveries($uuid, $r->query('status'));
    }

    public function replay(Request $r, string $uuid)
    {
        return $this->webhooks->replay($r->user(), $uuid);
    }

    // Import profiles and runs
    public function profiles()
    {
        return ['data' => $this->imports->profiles(), 'kinds' => collect(ImportProfileService::KINDS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values()];
    }

    public function createProfile(Request $r)
    {
        return response()->json($this->imports->create($r->user(), $r->all()), 201);
    }

    public function updateProfile(Request $r, string $uuid)
    {
        return $this->imports->update($r->user(), $uuid, $r->all());
    }

    public function runProfile(Request $r, string $uuid)
    {
        $r->validate(['file' => 'required|file|max:10240', 'dry_run' => 'sometimes|boolean']);

        return response()->json($this->imports->runUpload($r->user(), $uuid, $r->file('file'), $r->boolean('dry_run', true)), 201);
    }

    public function runs(Request $r)
    {
        return $this->imports->runs($r->query('profile'));
    }

    public function runItems(string $uuid)
    {
        return $this->imports->items($uuid);
    }

    public function retryRun(Request $r, string $uuid)
    {
        return $this->imports->retry($r->user(), $uuid);
    }

    // Health
    public function health()
    {
        return $this->health->overview();
    }

    public function diagnostics()
    {
        return ['data' => $this->health->diagnostics()];
    }
}
