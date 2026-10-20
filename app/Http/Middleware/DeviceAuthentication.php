<?php

namespace App\Http\Middleware;

use App\Domain\Attendance\Capture\Devices\DeviceTokens;
use App\Domain\Tenancy\AccountLifecycle;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticates attendance devices by their per-device bearer token and derives the tenant from the device itself
 * (a device can never choose or switch tenant). Unknown, revoked and inactive devices all receive the same 401.
 */
class DeviceAuthentication
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ?: $request->header('X-Device-Token');
        $device = DeviceTokens::resolve($token);
        $tenant = $device ? Tenant::query()->find($device->tenant_id) : null;
        if (! $device || ! $tenant || $device->status === 'inactive' || ! in_array('attendance', $tenant->settings['modules'] ?? [], true) || ! app(AccountLifecycle::class)->hasAccess($tenant)) {
            return response()->json(['message' => 'Invalid device credentials.'], 401);
        }
        $context = app(TenantContext::class);
        $context->id = $device->tenant_id;
        try {
            if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subSeconds(60))) {
                $device->forceFill(['last_seen_at' => now(), 'last_seen_ip' => $request->ip()])->saveQuietly();
            }
            $request->attributes->set('device', $device);

            return $next($request);
        } finally {
            $context->id = null;
        }
    }
}
