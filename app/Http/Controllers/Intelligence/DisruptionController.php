<?php

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Disruption\DisruptionPlanner;
use Illuminate\Http\Request;

/** Operational Disruption Recovery Planner. */
final class DisruptionController
{
    public function __construct(private DisruptionPlanner $planner) {}

    public function plan(Request $r)
    {
        $d = $r->validate(['from' => 'required|string|size:10', 'to' => 'required|string|size:10', 'min_available_pct' => 'nullable|integer|min:1|max:100']);

        return $this->planner->plan($r->user(), $d['from'], $d['to'], (int) ($d['min_available_pct'] ?? 80));
    }
}
