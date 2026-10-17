<?php

namespace App\Http\Controllers\Intelligence;

use App\Domain\Intelligence\Decisions\DecisionQualityService;
use Illuminate\Http\Request;

/** Manager Decision Quality: response behaviour, not performance. */
final class DecisionsController
{
    public function __construct(private DecisionQualityService $svc) {}

    public function report(Request $r)
    {
        $d = $r->validate(['from' => 'required|string|size:10', 'to' => 'required|string|size:10', 'target_hours' => 'nullable|integer|min:1|max:720']);

        return $this->svc->report($r->user(), $d['from'], $d['to'], (int) ($d['target_hours'] ?? 48));
    }
}
