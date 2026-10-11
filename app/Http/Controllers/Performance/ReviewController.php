<?php

namespace App\Http\Controllers\Performance;

use App\Domain\Performance\{CalibrationService, PerformanceAccess, ReviewService};
use App\Domain\Performance\Models\{CalibrationChange, Cycle, Goal, Response, Review};
use App\Domain\Performance\Support\Num;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ReviewController
{
    public function __construct(private ReviewService $reviews, private CalibrationService $calibration, private PerformanceAccess $access) {}

    private function review(string $uuid): Review
    {
        return Review::with('cycle.template.scale')->where('uuid', $uuid)->firstOrFail();
    }

    public function index(Request $r)
    {
        $u = $r->user(); $own = $this->access->own($u);
        $q = Review::with('cycle')->where(function ($w) use ($u, $own) {
            $w->whereIn('employee_id', $own ?: [0])->orWhereIn('id', Response::where('reviewer_user_id', $u->id)->select('review_id'));
            if ($u->allows('performance.view')) $w->orWhereIn('employee_id', $this->access->reachIds($u, 'performance.view'));
        })->orderByDesc('id');
        if ($r->filled('cycle_uuid')) $q->whereIn('cycle_id', Cycle::where('uuid', $r->query('cycle_uuid'))->select('id'));
        if ($r->filled('status')) $q->where('status', $r->query('status'));

        return $q->paginate(min(100, (int) $r->query('per_page', 25)))->through(function ($rev) use ($u) {
            $role = $this->reviews->role($u, $rev);
            $mine = Response::where('review_id', $rev->id)->where('reviewer_user_id', $u->id)->where('status', 'pending')->get(['uuid', 'reviewer_type', 'due_on']);

            return $this->reviews->header($rev) + ['role' => $role, 'pending_responses' => $mine->map(fn ($x) => ['uuid' => $x->uuid, 'type' => $x->reviewer_type, 'due_on' => $x->due_on?->toDateString()])->all()];
        });
    }

    public function show(Request $r, string $uuid)
    {
        return $this->reviews->present($r->user(), $this->review($uuid));
    }

    public function nominatePeers(Request $r, string $uuid)
    {
        $d = $r->validate(['employee_uuids' => 'required|array|min:1|max:8', 'employee_uuids.*' => 'uuid']);
        $peers = collect($d['employee_uuids'])->unique()->map(fn ($x) => $this->access->employeeByUuid($x))->all();
        $added = $this->reviews->nominatePeers($r->user(), $this->review($uuid), $peers);

        return response()->json(['added' => count($added)], 201);
    }

    public function reassignManager(Request $r, string $uuid)
    {
        $d = $r->validate(['manager_uuid' => 'required|uuid']);

        return $this->reviews->header($this->reviews->reassignManager($r->user(), $this->review($uuid), $this->access->employeeByUuid($d['manager_uuid']))->refresh());
    }

    public function submit(Request $r, string $uuid)
    {
        $d = $r->validate(['items' => 'required|array|min:1|max:60', 'items.*.section' => 'required|string|max:40', 'items.*.ref' => 'required|string|max:80', 'items.*.label' => 'nullable|string|max:200',
            'items.*.weight' => 'nullable|numeric|min:0.01|max:100', 'items.*.rating' => 'required|numeric', 'items.*.comment' => 'nullable|string|max:4000', 'comments' => 'nullable|string|max:8000']);
        $resp = Response::where('uuid', $uuid)->firstOrFail();
        abort_unless($resp->reviewer_user_id === $r->user()->id, 404);
        $resp = $this->reviews->submit($r->user(), $resp, $d['items'], $d['comments'] ?? null);

        return ['uuid' => $resp->uuid, 'status' => $resp->status, 'score' => $resp->score];
    }

    public function acknowledge(Request $r, string $uuid)
    {
        $d = $r->validate(['comment' => 'nullable|string|max:4000']);
        $rev = $this->reviews->acknowledge($r->user(), $this->review($uuid), $d['comment'] ?? null);

        return ['uuid' => $rev->uuid, 'status' => $rev->status, 'acknowledged_at' => $rev->acknowledged_at?->toIso8601String()];
    }

    // ---- calibration -----------------------------------------------------------------------------------------------------------

    public function startCalibration(Request $r, string $uuid)
    {
        $c = $this->calibration->start($r->user(), Cycle::with('template.scale')->where('uuid', $uuid)->firstOrFail());

        return ['uuid' => $c->uuid, 'status' => $c->status];
    }

    public function workspace(Request $r, string $uuid)
    {
        return $this->calibration->workspace($r->user(), Cycle::with('template.scale')->where('uuid', $uuid)->firstOrFail());
    }

    public function propose(Request $r, string $uuid)
    {
        $d = $r->validate(['to_score' => 'required|numeric', 'justification' => 'required|string|max:2000']);
        $c = $this->calibration->propose($r->user(), $this->review($uuid), Num::in($d['to_score']), $d['justification']);

        return response()->json(['uuid' => $c->uuid, 'status' => $c->status], 201);
    }

    public function decide(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approve', 'reject'])], 'note' => 'nullable|string|max:500']);
        $c = $this->calibration->decide($r->user(), CalibrationChange::where('uuid', $uuid)->firstOrFail(), $d['decision'], $d['note'] ?? null);

        return ['uuid' => $c->uuid, 'status' => $c->status];
    }

    public function finalize(Request $r, string $uuid)
    {
        return $this->calibration->finalize($r->user(), Cycle::with('template.scale')->where('uuid', $uuid)->firstOrFail());
    }
}
