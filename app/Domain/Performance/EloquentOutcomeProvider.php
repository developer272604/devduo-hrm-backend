<?php

namespace App\Domain\Performance;

use App\Domain\Performance\Contracts\PerformanceOutcomeProvider;
use App\Domain\Performance\Models\Review;
use App\Domain\Performance\Support\Num;

final class EloquentOutcomeProvider implements PerformanceOutcomeProvider
{
    public function outcomesFor(int $employeeId, ?string $asOfDate = null): array
    {
        $q = Review::where('employee_id', $employeeId)->whereIn('status', Review::FINAL)->with('cycle.template.scale')->orderByDesc('finalized_at')->orderByDesc('id');
        if ($asOfDate) $q->where('finalized_at', '<=', $asOfDate.' 23:59:59');

        return $q->get()->map(fn ($r) => [
            'review' => $r->uuid, 'cycle' => $r->cycle->uuid, 'cycle_name' => $r->cycle->name, 'period_start' => $r->cycle->start_date->toDateString(), 'period_end' => $r->cycle->end_date->toDateString(),
            'final_score' => Num::round(Num::in($r->final_score), 3), 'final_rating' => $r->final_rating, 'scale_min' => Num::round(Num::in($r->cycle->template->scale->min_value), 2),
            'scale_max' => Num::round(Num::in($r->cycle->template->scale->max_value), 2), 'finalized_at' => $r->finalized_at->toIso8601String(),
        ])->all();
    }

    public function latest(int $employeeId, ?string $asOfDate = null): ?array
    {
        return $this->outcomesFor($employeeId, $asOfDate)[0] ?? null;
    }
}
