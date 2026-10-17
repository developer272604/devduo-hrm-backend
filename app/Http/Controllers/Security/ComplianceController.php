<?php

namespace App\Http\Controllers\Security;

use App\Domain\Security\Compliance\ComplianceService;
use Illuminate\Http\Request;

/** Continuous Compliance Evidence Vault. */
final class ComplianceController
{
    public function __construct(private ComplianceService $svc) {}

    public function index()
    {
        return $this->svc->list();
    }

    public function show(string $uuid)
    {
        $c = $this->svc->find($uuid);

        return $this->svc->present($c) + ['evidence' => $this->svc->evidenceFor($c)];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9_-]*$/'], 'name' => 'required|string|max:200', 'description' => 'nullable|string|max:3000',
            'category' => 'required|in:'.implode(',', ComplianceService::CATEGORIES), 'frequency' => 'required|in:'.implode(',', array_keys(ComplianceService::FREQUENCIES)),
            'regulation_ref' => 'nullable|string|max:300', 'owner_user_id' => 'required|integer', 'next_due_on' => 'nullable|date_format:Y-m-d']);

        return response()->json($this->svc->present($this->svc->create($r->user(), $d)), 201);
    }

    public function update(Request $r, string $uuid)
    {
        $d = $r->validate(['name' => 'sometimes|string|max:200', 'description' => 'nullable|string|max:3000', 'regulation_ref' => 'nullable|string|max:300', 'owner_user_id' => 'sometimes|integer',
            'next_due_on' => 'nullable|date_format:Y-m-d', 'active' => 'sometimes|boolean']);

        return $this->svc->present($this->svc->update($r->user(), $this->svc->find($uuid), $d));
    }

    public function submit(Request $r, string $uuid)
    {
        $d = $r->validate(['collected_on' => 'required|date_format:Y-m-d', 'title' => 'required|string|max:200', 'reference' => 'required|string|max:500', 'note' => 'nullable|string|max:2000']);

        return response()->json($this->svc->presentEvidence($this->svc->submit($r->user(), $this->svc->find($uuid), $d)), 201);
    }

    public function review(Request $r, string $uuid)
    {
        $d = $r->validate(['accept' => 'required|boolean', 'note' => 'nullable|string|max:2000']);

        return $this->svc->presentEvidence($this->svc->review($r->user(), $uuid, (bool) $d['accept'], $d['note'] ?? null));
    }

    public function queue(Request $r)
    {
        return ['items' => $this->svc->awaitingReview($r->user())];
    }

    public function remind(Request $r)
    {
        return $this->svc->remind($r->user());
    }

    public function pack(Request $r)
    {
        $d = $r->validate(['from' => 'required|string|size:10', 'to' => 'required|string|size:10']);

        return $this->svc->pack($r->user(), $d['from'], $d['to']);
    }
}
