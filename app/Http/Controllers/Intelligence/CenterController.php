<?php

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\FindingService;
use App\Domain\Intelligence\RiskSignalService;
use App\Domain\Intelligence\ScenarioService;
use App\Domain\Intelligence\TimeStoryService;
use Illuminate\Http\Request;

/** Intelligence Center: stored findings (viewer-scoped), explain, actions, scenarios, risk signals, time story. */
final class CenterController
{
    public function __construct(private FindingService $findings) {}

    public function index(Request $r)
    {
        $d = $r->validate(['status' => 'nullable|in:active,all,open,acknowledged,snoozed,resolved,dismissed', 'domain' => 'nullable|string|max:60', 'severity' => 'nullable|in:info,warning,action_required,critical']);

        return $this->findings->list($r->user(), $d) + ['generated_at' => now()->toIso8601String()];
    }

    public function scan(Request $r)
    {
        return ['summary' => $this->findings->scan($r->user())] + $this->findings->list($r->user(), []);
    }

    public function show(Request $r, string $uuid)
    {
        $f = $this->findings->find($r->user(), $uuid);

        return $this->findings->present($f) + ['history' => $this->findings->history($f)];
    }

    public function explain(Request $r, string $uuid)
    {
        return $this->findings->explainFor($this->findings->find($r->user(), $uuid));
    }

    public function status(Request $r, string $uuid)
    {
        $d = $r->validate(['status' => 'required|in:open,acknowledged,snoozed,resolved,dismissed', 'reason' => 'nullable|string|max:1000', 'snoozed_until' => 'nullable|date']);

        return $this->findings->present($this->findings->transition($r->user(), $this->findings->find($r->user(), $uuid), $d['status'], $d['reason'] ?? null, $d['snoozed_until'] ?? null));
    }

    public function due(Request $r, string $uuid)
    {
        $d = $r->validate(['due_on' => 'nullable|date_format:Y-m-d']);

        return $this->findings->present($this->findings->setDue($r->user(), $this->findings->find($r->user(), $uuid), $d['due_on'] ?? null));
    }

    public function task(Request $r, string $uuid)
    {
        return $this->findings->createTask($r->user(), $this->findings->find($r->user(), $uuid));
    }

    public function bulkAcknowledge(Request $r)
    {
        $d = $r->validate(['uuids' => 'required|array|min:1|max:100', 'uuids.*' => 'string']);

        return $this->findings->bulkAcknowledge($r->user(), $d['uuids']);
    }

    public function scenarios(Request $r, ScenarioService $s)
    {
        return ['kinds' => $s->kinds($r->user()), 'runs' => $s->history($r->user())];
    }

    public function runScenario(Request $r, ScenarioService $s)
    {
        $d = $r->validate(['kind' => 'required|string|max:40', 'params' => 'nullable|array', 'title' => 'nullable|string|max:200']);

        return response()->json($s->run($r->user(), $d['kind'], $d['params'] ?? [], $d['title'] ?? null), 201);
    }

    public function scenario(Request $r, ScenarioService $s, string $uuid)
    {
        return $s->show($r->user(), $uuid);
    }

    public function riskSignals(Request $r, RiskSignalService $s)
    {
        return $s->cohorts($r->user());
    }

    public function settings(RiskSignalService $s)
    {
        return $s->settings();
    }

    public function saveSettings(Request $r, RiskSignalService $s)
    {
        $d = $r->validate(['risk_signals_enabled' => 'required|boolean', 'min_cohort' => 'required|integer|min:3|max:100']);

        return $s->saveSettings($r->user(), (bool) $d['risk_signals_enabled'], (int) $d['min_cohort']);
    }

    public function timeStory(Request $r, TimeStoryService $s, string $uuid)
    {
        return $s->story($r->user(), $uuid);
    }
}
