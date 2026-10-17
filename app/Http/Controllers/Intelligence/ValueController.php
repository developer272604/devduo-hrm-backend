<?php

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Value\ValueLedgerService;
use Illuminate\Http\Request;

/** Value ledger: verified recoveries, labelled estimates and written claims. Company-wide. */
final class ValueController
{
    public function __construct(private ValueLedgerService $svc) {}

    private function view(Request $r): void
    {
        $this->svc->authorize($r->user(), 'intelligence.value.view');
    }

    private function manage(Request $r): void
    {
        $this->svc->authorize($r->user(), 'intelligence.value.manage');
    }

    public function report(Request $r)
    {
        $this->view($r);
        $d = $r->validate(['from' => 'required|string|size:7', 'to' => 'required|string|size:7']);

        return $this->svc->report($r->user(), $d['from'], $d['to']);
    }

    public function assumptions(Request $r)
    {
        $this->view($r);

        return ['items' => $this->svc->assumptions()];
    }

    public function setAssumption(Request $r, string $uuid)
    {
        $this->manage($r);
        $d = $r->validate(['minutes_per_run' => 'required|integer|min:1|max:10000', 'hourly_cost' => 'nullable|numeric|min:0|max:1000000', 'currency' => 'nullable|string|size:3', 'basis' => 'required|string|max:1000']);

        return $this->svc->setAssumption($r->user(), $uuid, $d);
    }

    public function clearAssumption(Request $r, string $uuid)
    {
        $this->manage($r);
        $this->svc->clearAssumption($r->user(), $uuid);

        return response()->noContent();
    }

    public function addEntry(Request $r)
    {
        $this->manage($r);
        $d = $r->validate(['month' => 'required|string|size:7', 'category' => 'required|in:'.implode(',', ValueLedgerService::CATEGORIES), 'title' => 'required|string|max:200', 'basis' => 'required|string|max:2000',
            'amount' => 'nullable|numeric|min:0|max:1000000000000', 'currency' => 'nullable|string|size:3', 'hours' => 'nullable|numeric|min:0|max:100000']);

        return response()->json($this->svc->presentEntry($this->svc->addEntry($r->user(), $d)), 201);
    }

    public function deleteEntry(Request $r, string $uuid)
    {
        $this->manage($r);
        $this->svc->deleteEntry($r->user(), $uuid);

        return response()->noContent();
    }
}
