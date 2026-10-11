<?php

namespace App\Http\Controllers\Intelligence;

use App\Domain\Insights\ActionCenter;
use Illuminate\Http\Request;

final class FindingsController
{
    public function __invoke(Request $request, ActionCenter $center)
    {
        $data = $request->validate(['domain' => 'nullable|string|max:60']);
        $items = $center->for($request->user(), $data['domain'] ?? null);

        return ['generated_at' => now()->toIso8601String(), 'count' => count($items), 'items' => $items,
            'limitations' => 'Findings are permission-filtered module outputs; this endpoint does not create forecasts or override source records.'];
    }
}
