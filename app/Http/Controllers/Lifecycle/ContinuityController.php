<?php

namespace App\Http\Controllers\Lifecycle;

use App\Domain\Lifecycle\Continuity\ContinuityService;
use Illuminate\Http\Request;

/** Organizational Knowledge Continuity: critical responsibilities, backups, exit-aware risk and handover tasks. */
final class ContinuityController
{
    public function __construct(private ContinuityService $svc) {}

    public function index(Request $r)
    {
        $d = $r->validate(['status' => 'nullable|in:active,retired,all', 'owner' => 'nullable|uuid']);

        return $this->svc->list($r->user(), $d);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['owner_uuid' => 'required|uuid', 'title' => 'required|string|max:200', 'description' => 'nullable|string|max:3000', 'criticality' => 'required|in:'.implode(',', ContinuityService::CRITICALITY),
            'documented' => 'nullable|boolean', 'documentation_ref' => 'nullable|string|max:500', 'backup_uuid' => 'nullable|uuid', 'backup_trained' => 'nullable|boolean']);

        return response()->json($this->svc->present($this->svc->create($r->user(), $d)), 201);
    }

    public function update(Request $r, string $uuid)
    {
        $d = $r->validate(['title' => 'sometimes|string|max:200', 'description' => 'nullable|string|max:3000', 'criticality' => 'sometimes|in:'.implode(',', ContinuityService::CRITICALITY),
            'documented' => 'sometimes|boolean', 'documentation_ref' => 'nullable|string|max:500', 'backup_uuid' => 'nullable|uuid', 'backup_trained' => 'sometimes|boolean', 'status' => 'sometimes|in:active,retired']);

        return $this->svc->present($this->svc->update($r->user(), $uuid, $d));
    }

    public function risk(Request $r)
    {
        return $this->svc->risk($r->user());
    }

    public function handover(Request $r, string $uuid)
    {
        return $this->svc->handover($r->user(), $uuid);
    }
}
