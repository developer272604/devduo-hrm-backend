<?php

namespace App\Http\Middleware;

use App\Domain\Integrations\Api\ApiClientService;
use App\Domain\Tenancy\AccountLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * Public API authentication: "Authorization: Bearer dvk_..." (or X-Api-Key). The tenant comes from the client record only.
 * Order: key -> tenant module -> IP allow-list -> rate limit -> scope. Every authenticated attempt is written to the request audit.
 */
class ApiClientAuthentication
{
    public function __construct(private ApiClientService $clients) {}

    public function handle(Request $request, Closure $next, string ...$scopes)
    {
        $client = $this->clients->authenticate($request->bearerToken() ?: $request->header('X-Api-Key'));
        $tenant = $client ? Tenant::query()->find($client->tenant_id) : null;
        if (! $client || ! $tenant || ! in_array('integrations', $tenant->settings['modules'] ?? [], true) || ! app(AccountLifecycle::class)->hasAccess($tenant)) {
            return response()->json(['message' => 'Invalid API credentials.'], 401);
        }
        $context = app(TenantContext::class);
        $previous = $context->id;
        $context->id = $client->tenant_id;
        try {
            $response = $this->guard($request, $next, $client, $scopes);
            $this->clients->audit($client, $request->method(), '/'.$request->path(), $response->getStatusCode(), $request->ip());

            return $response;
        } finally {
            $context->id = $previous;
        }
    }

    private function guard(Request $request, Closure $next, $client, array $scopes)
    {
        if (! ApiClientService::ipAllowed($client, $request->ip())) {
            return response()->json(['message' => 'This IP address is not allowed for this API client.'], 403);
        }
        $limit = $this->clients->hit($client);
        $headers = ['X-RateLimit-Limit' => $limit['limit'], 'X-RateLimit-Remaining' => $limit['remaining'], 'X-RateLimit-Reset' => $limit['reset']];
        if (! $limit['allowed']) {
            return response()->json(['message' => 'Rate limit exceeded.'], 429, $headers + ['Retry-After' => $limit['reset']]);
        }
        foreach ($scopes as $scope) {
            if (! in_array($scope, $client->scopes ?? [], true)) {
                return response()->json(['message' => 'This API key does not have the required scope.', 'required_scope' => $scope], 403, $headers);
            }
        }
        $client->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('api_client', $client);
        $response = $next($request);
        foreach ($headers as $name => $value) {
            $response->headers->set($name, (string) $value);
        }

        return $response;
    }
}
