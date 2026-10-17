<?php

namespace App\Http\Controllers\Learning;

use App\Domain\Learning\Talent\CareerService;
use App\Domain\Learning\Talent\TalentService;
use Illuminate\Http\Request;

/** Internal Talent Opportunity Marketplace. */
final class TalentController
{
    public function __construct(private TalentService $svc, private CareerService $career) {}

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return ['title' => "$req|string|max:200", 'description' => 'nullable|string|max:5000', 'kind' => "$req|in:".implode(',', TalentService::KINDS), 'hours_per_week' => "$req|integer|min:1|max:60",
            'starts_on' => "$req|date_format:Y-m-d", 'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on', 'closes_on' => 'nullable|date_format:Y-m-d', 'openings' => 'nullable|integer|min:1|max:50',
            'skills' => ($creating ? 'nullable' : 'sometimes').'|array|max:15', 'skills.*.skill_uuid' => 'required|uuid', 'skills.*.required_rank' => 'required|integer|min:1|max:10', 'skills.*.mandatory' => 'nullable|boolean'];
    }

    public function career(Request $r)
    {
        return $this->career->forSelf($r->user());
    }

    public function browse(Request $r)
    {
        return $this->svc->browse($r->user());
    }

    public function mine(Request $r)
    {
        return $this->svc->mine($r->user());
    }

    public function profile(Request $r)
    {
        return $this->svc->profile($r->user());
    }

    public function saveProfile(Request $r)
    {
        $d = $r->validate(['discoverable' => 'required|boolean', 'spare_hours_per_week' => 'required|integer|min:0|max:60', 'interests' => 'nullable|string|max:500']);

        return $this->svc->saveProfile($r->user(), $d);
    }

    public function posted(Request $r)
    {
        return $this->svc->posted($r->user());
    }

    public function store(Request $r)
    {
        $o = $this->svc->createOpportunity($r->user(), $r->validate($this->rules(true)));

        return response()->json($this->svc->presentOpportunity($o), 201);
    }

    public function show(Request $r, string $uuid)
    {
        $o = $this->svc->find($uuid);

        return $this->svc->presentOpportunity($o) + ['history' => $this->svc->history($o)];
    }

    public function update(Request $r, string $uuid)
    {
        return $this->svc->presentOpportunity($this->svc->updateOpportunity($r->user(), $this->svc->find($uuid), $r->validate($this->rules(false))));
    }

    public function status(Request $r, string $uuid)
    {
        $d = $r->validate(['status' => 'required|in:open,closed,cancelled', 'reason' => 'nullable|string|max:1000']);

        return $this->svc->presentOpportunity($this->svc->setStatus($r->user(), $this->svc->find($uuid), $d['status'], $d['reason'] ?? null));
    }

    public function candidates(Request $r, string $uuid)
    {
        return $this->svc->candidates($r->user(), $this->svc->find($uuid));
    }

    public function apply(Request $r, string $uuid)
    {
        $d = $r->validate(['note' => 'nullable|string|max:1000']);
        $a = $this->svc->apply($r->user(), $this->svc->find($uuid), $d['note'] ?? null);

        return response()->json($this->svc->presentApplication($a, true), 201);
    }

    public function withdraw(Request $r, string $uuid)
    {
        return $this->svc->presentApplication($this->svc->withdraw($r->user(), $this->svc->applicationFor($uuid)), true);
    }

    public function shortlist(Request $r, string $uuid)
    {
        return $this->svc->presentApplication($this->svc->shortlist($r->user(), $this->svc->applicationFor($uuid)), true);
    }

    public function decline(Request $r, string $uuid)
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);

        return $this->svc->presentApplication($this->svc->decline($r->user(), $this->svc->applicationFor($uuid), $d['reason']), true);
    }

    public function propose(Request $r, string $uuid)
    {
        return $this->svc->proposePlacement($r->user(), $this->svc->applicationFor($uuid));
    }

    public function approvals(Request $r)
    {
        return $this->svc->awaitingRelease($r->user());
    }

    public function release(Request $r, string $uuid)
    {
        $d = $r->validate(['approve' => 'required|boolean', 'reason' => 'nullable|string|max:1000']);

        return $this->svc->presentApplication($this->svc->decideRelease($r->user(), $this->svc->applicationFor($uuid), (bool) $d['approve'], $d['reason'] ?? null), true);
    }

    public function complete(Request $r, string $uuid)
    {
        $d = $r->validate(['skills' => 'nullable|array|max:15', 'skills.*' => 'uuid', 'note' => 'nullable|string|max:800']);

        return $this->svc->presentApplication($this->svc->complete($r->user(), $this->svc->applicationFor($uuid), $d['skills'] ?? [], $d['note'] ?? null), true);
    }

    public function endEarly(Request $r, string $uuid)
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);

        return $this->svc->presentApplication($this->svc->endEarly($r->user(), $this->svc->applicationFor($uuid), $d['reason']), true);
    }
}
