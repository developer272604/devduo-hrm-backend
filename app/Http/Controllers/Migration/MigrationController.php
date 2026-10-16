<?php

namespace App\Http\Controllers\Migration;

use App\Domain\Migration\MigrationCatalog;
use App\Domain\Migration\MigrationService;
use Illuminate\Http\Request;

/** Migration Studio: ordered, verified moves from another HRMS using the existing module imports. */
final class MigrationController
{
    public function __construct(private MigrationService $svc) {}

    private function stages(): string
    {
        return 'required|in:'.implode(',', MigrationCatalog::order());
    }

    public function index()
    {
        return ['items' => $this->svc->index(), 'stages' => collect(MigrationCatalog::STAGES)->map(fn ($s, $k) => ['code' => $k, 'label' => $s['label'], 'depends_on' => $s['depends'], 'where_to_import' => $s['where']])->values()->all()];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:200', 'source_system' => 'nullable|string|max:120', 'target_cutover_on' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:4000']);

        return response()->json($this->svc->show($this->svc->create($r->user(), $d)), 201);
    }

    public function show(string $uuid)
    {
        return $this->svc->show($this->svc->find($uuid));
    }

    public function link(Request $r, string $uuid)
    {
        $d = $r->validate(['stage' => $this->stages(), 'import_uuid' => 'required|uuid']);

        return $this->svc->show($this->svc->linkImport($r->user(), $this->svc->find($uuid), $d['stage'], $d['import_uuid']));
    }

    public function unlink(Request $r, string $uuid)
    {
        $d = $r->validate(['stage' => $this->stages()]);

        return $this->svc->show($this->svc->unlink($r->user(), $this->svc->find($uuid), $d['stage']));
    }

    public function skip(Request $r, string $uuid)
    {
        $d = $r->validate(['stage' => $this->stages(), 'reason' => 'required|string|max:1000']);

        return $this->svc->show($this->svc->skip($r->user(), $this->svc->find($uuid), $d['stage'], $d['reason']));
    }

    public function reconcile(Request $r, string $uuid)
    {
        $d = $r->validate(['stage' => $this->stages(), 'metric' => 'required|in:'.implode(',', array_keys(MigrationCatalog::METRICS)), 'source_value' => 'required|numeric', 'tolerance' => 'nullable|numeric|min:0', 'note' => 'nullable|string|max:1000']);

        return $this->svc->show($this->svc->reconcile($r->user(), $this->svc->find($uuid), $d['stage'], $d['metric'], (float) $d['source_value'], (float) ($d['tolerance'] ?? 0), $d['note'] ?? null));
    }

    public function verify(Request $r, string $uuid)
    {
        $d = $r->validate(['stage' => $this->stages(), 'override_reason' => 'nullable|string|max:1000']);

        return $this->svc->show($this->svc->verify($r->user(), $this->svc->find($uuid), $d['stage'], $d['override_reason'] ?? null));
    }

    public function reopen(Request $r, string $uuid)
    {
        $d = $r->validate(['stage' => $this->stages(), 'reason' => 'required|string|max:1000']);

        return $this->svc->show($this->svc->reopen($r->user(), $this->svc->find($uuid), $d['stage'], $d['reason']));
    }

    public function goLive(Request $r, string $uuid)
    {
        return $this->svc->show($this->svc->goLive($r->user(), $this->svc->find($uuid)));
    }

    public function cancel(Request $r, string $uuid)
    {
        $d = $r->validate(['reason' => 'required|string|max:1000']);

        return $this->svc->show($this->svc->cancel($r->user(), $this->svc->find($uuid), $d['reason']));
    }
}
