<?php

namespace App\Http\Controllers\Performance;

use App\Domain\Performance\{CollaborationService, PerformanceAccess, PipService};
use App\Domain\Performance\Models\{DevAction, DevPlan, Feedback, Pip, PipCheckpoint};
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Feedback, 1:1 notes, development plans and confidential improvement plans. */
final class CollabController
{
    public function __construct(private CollaborationService $collab, private PipService $pips, private PerformanceAccess $access) {}

    // ---- feedback --------------------------------------------------------------------------------------------------------------
    public function giveFeedback(Request $r)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'kind' => ['nullable', Rule::in(['feedback', 'recognition', 'development'])], 'visibility' => ['nullable', Rule::in(['recipient', 'recipient_manager'])], 'message' => 'required|string|min:3|max:4000']);
        $f = $this->collab->giveFeedback($r->user(), $this->access->employeeByUuid($d['employee_uuid']), $d);

        return response()->json($this->collab->presentFeedback($f, $r->user()), 201);
    }

    public function received(Request $r)
    {
        $own = $this->access->own($r->user());

        return Feedback::whereIn('to_employee_id', $own ?: [0])->latest('id')->paginate(25)->through(fn ($f) => $this->collab->presentFeedback($f, $r->user()));
    }

    public function given(Request $r)
    {
        return Feedback::where('from_user_id', $r->user()->id)->latest('id')->paginate(25)->through(fn ($f) => $this->collab->presentFeedback($f, $r->user()));
    }

    public function feedbackAbout(Request $r, string $uuid)
    {
        return $this->collab->feedbackAbout($r->user(), $this->access->employeeByUuid($uuid))->paginate(25)->through(fn ($f) => $this->collab->presentFeedback($f, $r->user()));
    }

    // ---- 1:1 ---------------------------------------------------------------------------------------------------------------------
    public function recordOneOnOne(Request $r)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'held_on' => 'required|date_format:Y-m-d', 'shared_notes' => 'nullable|string|max:8000', 'private_notes' => 'nullable|string|max:8000',
            'action_items' => 'nullable|array|max:20', 'action_items.*.text' => 'required|string|max:300', 'action_items.*.owner' => ['nullable', Rule::in(['employee', 'manager'])]]);
        $n = $this->collab->recordOneOnOne($r->user(), $this->access->employeeByUuid($d['employee_uuid']), $d);

        return response()->json($this->collab->presentOneOnOne($n, $r->user()), 201);
    }

    /** The viewer's own 1:1 journal: sessions about them, plus sessions they authored (private notes only for the author). */
    public function myOneOnOnes(Request $r)
    {
        $own = $this->access->own($r->user()) ?: [0];

        return \App\Domain\Performance\Models\OneOnOne::where(function ($q) use ($own, $r) {
            $q->where('author_id', $r->user()->id)->orWhere(fn ($w) => $w->whereIn('employee_id', $own)->whereNotNull('shared_notes'));
        })->orderByDesc('held_on')->orderByDesc('id')->paginate(25)->through(function ($n) use ($r) {
            $e = Employee::with('person')->find($n->employee_id);

            return $this->collab->presentOneOnOne($n, $r->user()) + ['employee' => ['uuid' => $e->uuid, 'name' => trim(($e->person->legal_first_name ?? '').' '.($e->person->legal_last_name ?? ''))]];
        });
    }

    public function oneOnOnes(Request $r, string $uuid)
    {
        return $this->collab->oneOnOnes($r->user(), $this->access->employeeByUuid($uuid))->paginate(25)->through(fn ($n) => $this->collab->presentOneOnOne($n, $r->user()));
    }

    // ---- development plans -------------------------------------------------------------------------------------------------------
    public function plans(Request $r)
    {
        return $this->collab->plans($r->user())->paginate(25)->through(fn ($p) => $this->collab->presentPlan($p));
    }

    public function createPlan(Request $r)
    {
        $d = $r->validate(['employee_uuid' => 'nullable|uuid', 'title' => 'required|string|max:200', 'description' => 'nullable|string|max:4000', 'target_date' => 'nullable|date_format:Y-m-d', 'goal_uuid' => 'nullable|uuid',
            'actions' => 'array|max:30', 'actions.*.title' => 'required|string|max:200', 'actions.*.skill_id' => 'nullable|integer', 'actions.*.learning_ref' => 'nullable|string|max:120', 'actions.*.due_on' => 'nullable|date_format:Y-m-d']);
        $emp = ! empty($d['employee_uuid']) ? $this->access->employeeByUuid($d['employee_uuid']) : Employee::findOrFail($this->access->own($r->user())[0] ?? 0);

        return response()->json($this->collab->presentPlan($this->collab->createPlan($r->user(), $emp, $d)), 201);
    }

    public function addAction(Request $r, string $uuid)
    {
        $d = $r->validate(['title' => 'required|string|max:200', 'skill_id' => 'nullable|integer', 'learning_ref' => 'nullable|string|max:120', 'due_on' => 'nullable|date_format:Y-m-d']);
        $p = DevPlan::where('uuid', $uuid)->firstOrFail();
        abort_unless($this->collab->canManagePlan($r->user(), $p), 403, 'You cannot change this plan.');
        $this->collab->addAction($p, $d);

        return response()->json($this->collab->presentPlan($p->refresh()), 201);
    }

    public function updateAction(Request $r, string $uuid)
    {
        $d = $r->validate(['status' => ['required', Rule::in(['open', 'in_progress', 'done', 'cancelled'])]]);
        $a = DevAction::where('uuid', $uuid)->firstOrFail(); $p = DevPlan::findOrFail($a->plan_id);
        abort_unless($this->collab->canManagePlan($r->user(), $p), 403, 'You cannot change this plan.');
        $a->update(['status' => $d['status'], 'completed_at' => $d['status'] === 'done' ? now() : null]);

        return $this->collab->presentPlan($p);
    }

    // ---- PIP ---------------------------------------------------------------------------------------------------------------------
    public function pipList(Request $r)
    {
        return Pip::whereIn('employee_id', $this->access->reachIds($r->user(), 'performance.pip.manage'))->latest('id')->paginate(25)->through(function ($p) {
            $e = Employee::with('person')->find($p->employee_id);

            return ['uuid' => $p->uuid, 'status' => $p->status, 'employee' => ['uuid' => $e->uuid, 'name' => trim(($e->person->legal_first_name ?? '').' '.($e->person->legal_last_name ?? ''))], 'start_date' => $p->start_date->toDateString(), 'end_date' => $p->end_date->toDateString()];
        });
    }

    public function pipMine(Request $r)
    {
        $p = Pip::whereIn('employee_id', $this->access->own($r->user()) ?: [0])->where('status', '!=', 'draft')->latest('id')->get();

        return ['data' => $p->map(fn ($x) => $this->pips->present($x))->all()];
    }

    public function pipShow(Request $r, string $uuid)
    {
        $p = Pip::where('uuid', $uuid)->firstOrFail();
        abort_unless($this->pips->canRead($r->user(), $p), 404);

        return $this->pips->present($p);
    }

    public function pipCreate(Request $r)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'reason' => 'required|string|min:10|max:8000', 'support_plan' => 'nullable|string|max:8000', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d',
            'checkpoints' => 'array|max:12', 'checkpoints.*.due_on' => 'required|date_format:Y-m-d', 'checkpoints.*.expectation' => 'required|string|max:2000']);
        $p = $this->pips->create($r->user(), $this->access->employeeByUuid($d['employee_uuid']), $d);

        return response()->json($this->pips->present($p, false), 201);
    }

    public function pipActivate(Request $r, string $uuid)
    {
        return $this->pips->present($this->pips->activate($r->user(), Pip::where('uuid', $uuid)->firstOrFail()), false);
    }

    public function pipCheckpoint(Request $r, string $uuid, string $cuuid)
    {
        $d = $r->validate(['status' => ['required', Rule::in(['met', 'partially_met', 'not_met'])], 'note' => 'nullable|string|max:4000']);
        $p = Pip::where('uuid', $uuid)->firstOrFail();
        $this->pips->recordCheckpoint($r->user(), $p, PipCheckpoint::where('pip_id', $p->id)->where('uuid', $cuuid)->firstOrFail(), $d['status'], $d['note'] ?? null);

        return $this->pips->present($p->refresh(), false);
    }

    public function pipComplete(Request $r, string $uuid)
    {
        $d = $r->validate(['outcome' => ['required', Rule::in(['success', 'unsuccessful', 'cancelled'])], 'note' => 'required|string|min:5|max:4000']);

        return $this->pips->present($this->pips->complete($r->user(), Pip::where('uuid', $uuid)->firstOrFail(), $d['outcome'], $d['note']), false);
    }
}
