<?php

namespace App\Http\Controllers\Integrations;

use App\Domain\Integrations\IntegrationService;
use Illuminate\Http\Request;

final class IntegrationController
{
    public function __construct(private IntegrationService $service) {}

    public function providers()
    {
        return ['data' => $this->service->providers()];
    }

    public function connections()
    {
        return $this->service->connections();
    }

    public function create(Request $request)
    {
        return response()->json($this->service->create($request->user(), $request->all()), 201);
    }

    public function test(Request $request, string $uuid)
    {
        return $this->service->test($request->user(), $uuid);
    }

    public function runs(string $uuid)
    {
        return $this->service->runs($uuid);
    }

    public function revoke(Request $request, string $uuid)
    {
        return $this->service->revoke($request->user(), $uuid);
    }
}
