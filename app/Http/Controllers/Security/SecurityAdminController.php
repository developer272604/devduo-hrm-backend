<?php

namespace App\Http\Controllers\Security;

use App\Domain\Security\AuditSearchService;
use App\Domain\Security\LegalHoldService;
use App\Domain\Security\SecuritySettingsService;
use Illuminate\Http\Request;

final class SecurityAdminController
{
    public function __construct(private SecuritySettingsService $settings, private AuditSearchService $audit, private LegalHoldService $holds) {}

    public function settings(Request $request)
    {
        return $this->settings->show($request->user());
    }

    public function updateSettings(Request $request)
    {
        return $this->settings->update($request->user(), $request->all());
    }

    public function auditSearch(Request $request)
    {
        return $this->audit->search($request->query());
    }

    public function auditVerify()
    {
        return $this->audit->verify();
    }

    public function auditExport(Request $request)
    {
        return $this->audit->export($request->query());
    }

    public function holds()
    {
        return ['data' => $this->holds->holds()];
    }

    public function placeHold(Request $request)
    {
        return response()->json($this->holds->present($this->holds->place($request->user(), $request->all())), 201);
    }

    public function releaseHold(Request $request, string $uuid)
    {
        return $this->holds->present($this->holds->release($request->user(), $uuid, (string) $request->input('reason')));
    }

    public function retention()
    {
        return ['data' => $this->holds->retention()];
    }

    public function saveRetention(Request $request, ?string $uuid = null)
    {
        $policy = $this->holds->saveRetention($request->user(), $request->all(), $uuid);

        return response()->json(collect($this->holds->retention())->firstWhere('uuid', $policy->uuid), $uuid ? 200 : 201);
    }
}
