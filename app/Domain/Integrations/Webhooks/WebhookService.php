<?php

namespace App\Domain\Integrations\Webhooks;

use App\Domain\Audit\Audit;
use App\Domain\Events\DomainEvent;
use App\Domain\Integrations\Models\WebhookDelivery;
use App\Domain\Integrations\Models\WebhookEndpoint;
use App\Domain\Workflow\WorkflowEventCatalog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Outbound webhooks. Endpoint registry + durable delivery queue: events are copied (minimized) into integration_webhook_deliveries
 * under a unique (endpoint, idempotency key), then delivered with retries, exponential backoff and a dead-letter state.
 */
final class WebhookService
{
    public const MAX_ATTEMPTS = 6;

    /** Seconds to wait before attempt N+1 (index = attempts already made - 1). */
    public const BACKOFF = [60, 300, 1800, 7200, 21600];

    public const LEASE_SECONDS = 300;

    /** Only employee-linked, non-sensitive business events can be subscribed to (same catalog as workflow triggers). */
    public static function eventTypes(): array
    {
        return array_keys(WorkflowEventCatalog::EVENTS);
    }

    public function list(): array
    {
        return WebhookEndpoint::query()->latest('id')->get()->map(fn ($e) => $this->present($e))->all();
    }

    /** @return array{endpoint:array,secret:string} */
    public function create(User $actor, array $input): array
    {
        $data = $this->validated($input, true);
        $secret = 'whsec_'.Str::random(40);
        $endpoint = WebhookEndpoint::create([
            'name' => $data['name'], 'url' => $data['url'], 'secret' => $secret, 'events' => array_values(array_unique($data['events'])), 'status' => 'active',
            'allow_insecure' => (bool) ($data['allow_insecure'] ?? false), 'last_event_id' => (int) DomainEvent::query()->max('id'), 'created_by' => $actor->id,
        ]);
        Audit::record('integration.webhook.created', 'integrations', 'webhook:'.$endpoint->uuid, ['events' => $endpoint->events]);

        return ['endpoint' => $this->present($endpoint), 'secret' => $secret];
    }

    public function update(User $actor, string $uuid, array $input): array
    {
        $endpoint = WebhookEndpoint::byUuid($uuid);
        $merged = $input + ['name' => $endpoint->name, 'url' => $endpoint->url, 'events' => $endpoint->events, 'allow_insecure' => $endpoint->allow_insecure];
        $data = $this->validated($merged, false);
        $endpoint->update(['name' => $data['name'], 'url' => $data['url'], 'events' => array_values(array_unique($data['events'])), 'allow_insecure' => (bool) ($data['allow_insecure'] ?? false)]
            + (isset($input['status']) && in_array($input['status'], ['active', 'disabled'], true) ? ['status' => $input['status']] : []));
        Audit::record('integration.webhook.updated', 'integrations', 'webhook:'.$endpoint->uuid, ['fields' => array_keys($input)]);

        return $this->present($endpoint->refresh());
    }

    /** @return array{endpoint:array,secret:string} */
    public function rotateSecret(User $actor, string $uuid): array
    {
        $endpoint = WebhookEndpoint::byUuid($uuid);
        $secret = 'whsec_'.Str::random(40);
        $endpoint->forceFill(['secret' => $secret, 'secret_rotated_at' => now()])->save();
        Audit::record('integration.webhook.secret_rotated', 'integrations', 'webhook:'.$endpoint->uuid);

        return ['endpoint' => $this->present($endpoint->refresh()), 'secret' => $secret];
    }

    public function delete(User $actor, string $uuid): void
    {
        $endpoint = WebhookEndpoint::byUuid($uuid);
        $endpoint->forceFill(['status' => 'disabled'])->save();
        WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->whereIn('status', ['pending', 'retry'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        Audit::record('integration.webhook.disabled', 'integrations', 'webhook:'.$endpoint->uuid);
    }

    public function deliveries(string $endpointUuid, ?string $status): array
    {
        $endpoint = WebhookEndpoint::byUuid($endpointUuid);

        return WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->when($status, fn ($q) => $q->where('status', $status))->latest('id')->paginate(25)
            ->through(fn ($d) => $this->presentDelivery($d))->toArray();
    }

    /** Queues a signed test event (idempotent per call through a unique key). */
    public function test(User $actor, string $uuid): array
    {
        $endpoint = WebhookEndpoint::byUuid($uuid);
        abort_if($endpoint->status !== 'active', 409, 'Enable the endpoint first.');
        $key = 'test:'.Str::uuid();
        $d = $this->enqueue($endpoint, 'webhook.test', $key, ['id' => (string) Str::uuid(), 'type' => 'webhook.test', 'occurred_at' => now()->toIso8601String()]);
        Audit::record('integration.webhook.test_queued', 'integrations', 'webhook:'.$endpoint->uuid);

        return $this->presentDelivery($d);
    }

    /** Manual replay: the same payload and idempotency key are sent again so receivers can deduplicate. */
    public function replay(User $actor, string $deliveryUuid): array
    {
        return DB::transaction(function () use ($actor, $deliveryUuid) {
            $d = WebhookDelivery::query()->where('uuid', $deliveryUuid)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($d->status, ['delivered', 'dead_letter', 'retry', 'cancelled'], true), 409, 'This delivery is still being processed.');
            $endpoint = WebhookEndpoint::query()->findOrFail($d->endpoint_id);
            abort_if($endpoint->status !== 'active', 409, 'Enable the endpoint before replaying deliveries.');
            $d->forceFill(['status' => 'pending', 'attempts' => 0, 'next_at' => now(), 'replays' => $d->replays + 1, 'last_error' => null, 'delivered_at' => null])->save();
            Audit::record('integration.webhook.replayed', 'integrations', 'webhook_delivery:'.$d->uuid, ['event' => $d->event_type]);

            return $this->presentDelivery($d->refresh());
        });
    }

    /** Copies new subscribed domain events into the delivery queue. Safe to run repeatedly. @return int deliveries created */
    public function enqueueEvents(): int
    {
        $created = 0;
        foreach (WebhookEndpoint::query()->where('status', 'active')->get() as $endpoint) {
            DB::transaction(function () use ($endpoint, &$created) {
                $endpoint = WebhookEndpoint::query()->whereKey($endpoint->id)->lockForUpdate()->first();
                $events = DomainEvent::query()->where('id', '>', $endpoint->last_event_id)->orderBy('id')->limit(200)->get();
                foreach ($events as $event) {
                    if (in_array($event->type, $endpoint->events, true) && ! in_array($event->classification, ['highly_sensitive', 'system_restricted'], true)) {
                        $before = WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->count();
                        $this->enqueue($endpoint, $event->type, 'evt:'.$event->uuid, $this->payload($event));
                        $created += WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->count() - $before;
                    }
                }
                if ($events->isNotEmpty()) {
                    $endpoint->forceFill(['last_event_id' => $events->last()->id])->save();
                }
            });
        }

        return $created;
    }

    /** Payload minimization: identifiers, type, timestamp and status transition only. No names, summaries or free text. */
    public function payload(DomainEvent $event): array
    {
        $employee = $event->employee_id ? DB::table('employees')->where('id', $event->employee_id)->where('tenant_id', $event->tenant_id)->first(['uuid', 'employee_number']) : null;
        $data = ['id' => $event->uuid, 'type' => $event->type, 'occurred_at' => $event->occurred_at?->toIso8601String(), 'employee_id' => $employee?->uuid, 'employee_number' => $employee?->employee_number];
        foreach (['from', 'to'] as $key) {
            if (is_string(data_get($event->payload, $key)) && mb_strlen(data_get($event->payload, $key)) <= 40) {
                $data['data'][$key] = data_get($event->payload, $key);
            }
        }

        return $data;
    }

    /** Delivers due items. @return array{sent:int,failed:int,dead:int} */
    public function deliverDue(int $limit = 100): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'dead' => 0];
        $ids = WebhookDelivery::query()->where(function ($q) {
            $q->whereIn('status', ['pending', 'retry', 'sending'])->where('next_at', '<=', now());
        })->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $result = $this->deliverOne($id);
            if ($result) {
                $out[$result]++;
            }
        }

        return $out;
    }

    private function deliverOne(int $id): ?string
    {
        // Claim with a lease so concurrent workers do not double-send; a crashed worker's lease simply expires.
        $claimed = DB::transaction(function () use ($id) {
            $d = WebhookDelivery::query()->whereKey($id)->lockForUpdate()->first();
            if (! $d || ! in_array($d->status, ['pending', 'retry', 'sending'], true) || ! $d->next_at || $d->next_at->isFuture()) {
                return null;
            }
            $d->forceFill(['status' => 'sending', 'attempts' => $d->attempts + 1, 'next_at' => now()->addSeconds(self::LEASE_SECONDS)])->save();

            return $d;
        });
        if (! $claimed) {
            return null;
        }
        $endpoint = WebhookEndpoint::query()->find($claimed->endpoint_id);
        if (! $endpoint || $endpoint->status !== 'active') {
            $claimed->forceFill(['status' => 'cancelled', 'last_error' => 'Endpoint disabled.'])->save();

            return null;
        }
        $code = null;
        try {
            $target = UrlGuard::check($endpoint->url, $endpoint->allow_insecure);
            $body = json_encode($claimed->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options = ['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$target['host'].':'.$target['port'].':'.$target['ip']]]];
            $response = Http::withOptions($options)->timeout(10)->connectTimeout(5)->withBody($body, 'application/json')->withHeaders([
                'User-Agent' => 'devduo-hrm-webhooks/1',
                'X-Devduo-Signature' => WebhookSigner::sign($endpoint->secret, $body),
                'X-Devduo-Event' => $claimed->event_type, 'X-Devduo-Delivery' => $claimed->uuid, 'Idempotency-Key' => $claimed->idempotency_key,
            ])->post($endpoint->url);
            $code = $response->status();
            $ok = $response->successful();
            $error = $ok ? null : 'HTTP '.$code;
        } catch (\InvalidArgumentException $e) {
            $ok = false;
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $ok = false;
            $error = 'Connection failed.';
        }
        if ($ok) {
            $claimed->forceFill(['status' => 'delivered', 'delivered_at' => now(), 'last_status_code' => $code, 'last_error' => null, 'next_at' => null])->save();
            $endpoint->forceFill(['consecutive_failures' => 0, 'last_success_at' => now()])->save();

            return 'sent';
        }
        $dead = $claimed->attempts >= self::MAX_ATTEMPTS;
        $claimed->forceFill(['status' => $dead ? 'dead_letter' : 'retry', 'last_status_code' => $code, 'last_error' => mb_substr((string) $error, 0, 300),
            'next_at' => $dead ? null : now()->addSeconds(self::BACKOFF[min($claimed->attempts, count(self::BACKOFF)) - 1])])->save();
        $endpoint->forceFill(['consecutive_failures' => $endpoint->consecutive_failures + 1, 'last_failure_at' => now()])->save();

        return $dead ? 'dead' : 'failed';
    }

    private function enqueue(WebhookEndpoint $endpoint, string $type, string $key, array $payload): WebhookDelivery
    {
        $existing = WebhookDelivery::query()->where('endpoint_id', $endpoint->id)->where('idempotency_key', $key)->first();
        if ($existing) {
            return $existing;
        }

        return WebhookDelivery::create(['endpoint_id' => $endpoint->id, 'event_type' => $type, 'idempotency_key' => $key, 'payload' => $payload, 'status' => 'pending', 'next_at' => now()]);
    }

    private function validated(array $input, bool $creating): array
    {
        $data = validator($input, [
            'name' => 'required|string|max:150', 'url' => 'required|string|max:500',
            'events' => 'required|array|min:1|max:30', 'events.*' => 'required|string|in:'.implode(',', self::eventTypes()),
            'allow_insecure' => 'sometimes|boolean',
        ])->validate();
        try {
            UrlGuard::check($data['url'], (bool) ($data['allow_insecure'] ?? false));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => [$e->getMessage()]]);
        }

        return $data;
    }

    public function present(WebhookEndpoint $e): array
    {
        $pending = WebhookDelivery::query()->where('endpoint_id', $e->id)->whereIn('status', ['pending', 'retry', 'sending'])->count();
        $dead = WebhookDelivery::query()->where('endpoint_id', $e->id)->where('status', 'dead_letter')->count();

        return ['uuid' => $e->uuid, 'name' => $e->name, 'url' => $e->url, 'events' => $e->events, 'status' => $e->status, 'allow_insecure' => $e->allow_insecure,
            'consecutive_failures' => $e->consecutive_failures, 'pending_deliveries' => $pending, 'dead_letters' => $dead, 'last_success_at' => $e->last_success_at?->toIso8601String(),
            'last_failure_at' => $e->last_failure_at?->toIso8601String(), 'secret_rotated_at' => $e->secret_rotated_at?->toIso8601String(), 'created_at' => $e->created_at?->toIso8601String()];
    }

    public function presentDelivery(WebhookDelivery $d): array
    {
        return ['uuid' => $d->uuid, 'event_type' => $d->event_type, 'idempotency_key' => $d->idempotency_key, 'status' => $d->status, 'attempts' => $d->attempts, 'replays' => $d->replays,
            'next_at' => $d->next_at?->toIso8601String(), 'last_status_code' => $d->last_status_code, 'last_error' => $d->last_error, 'delivered_at' => $d->delivered_at?->toIso8601String(), 'created_at' => $d->created_at?->toIso8601String()];
    }
}
