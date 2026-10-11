<?php

namespace App\Domain\Performance;

use App\Domain\Payroll\Support\Decimal;
use App\Domain\Performance\Models\{Cycle, RatingScale, Response, Review, Template};
use App\Domain\Performance\Support\Num;

/**
 * Decimal-safe weighted scoring with an explainable trace.
 *   item rating --(item weights)--> section average --(section weights)--> response score
 *   response scores --(reviewer-type weights, renormalized over submitted types)--> review score
 * Peer responses are averaged and only counted when the cycle's minimum number of peer responses is met (confidentiality).
 * The engine never decides anything: it computes a proposed score that humans calibrate and finalize.
 */
final class ScoreEngine
{
    /** @param iterable<object{section:string,label:string,weight:mixed,rating:mixed}> $items @return array{0:?string,1:array} */
    public function responseScore(Template $tpl, iterable $items): array
    {
        $by = [];
        foreach ($items as $i) $by[$i->section][] = $i;
        $sections = collect($tpl->sections)->keyBy('key');
        $lines = []; $sumW = Decimal::of(0); $sum = Decimal::of(0);
        foreach ($by as $key => $rows) {
            $w = Decimal::sum(array_map(fn ($r) => Num::in($r->weight), $rows));
            if ($w->isZero()) continue;
            $avg = Decimal::div(Decimal::sum(array_map(fn ($r) => Decimal::mul(Num::in($r->weight), Num::in($r->rating)), $rows)), $w, 6);
            $sw = Num::in($sections[$key]['weight'] ?? 1);
            $sum = $sum->plus(Decimal::mul($sw, $avg)); $sumW = $sumW->plus(Num::d($sw));
            $lines[] = ['step' => 'section', 'section' => $key, 'title' => $sections[$key]['title'] ?? $key, 'section_weight' => Num::round($sw, 2), 'average' => Num::round($avg, 3),
                'items' => array_map(fn ($r) => ['label' => $r->label, 'rating' => Num::round(Num::in($r->rating), 2), 'weight' => Num::round(Num::in($r->weight), 2)], $rows)];
        }
        if ($sumW->isZero()) return [null, $lines];
        $score = Num::round(Decimal::div($sum, $sumW, 8), 3);
        $lines[] = ['step' => 'response', 'formula' => 'sum(section weight x section average) / sum(section weights)', 'score' => $score];

        return [$score, $lines];
    }

    /** @return array{0:?string,1:array} computed score and trace */
    public function reviewScore(Review $review, Cycle $cycle, Template $tpl): array
    {
        $weights = $tpl->reviewer_weights;
        $minPeers = max(1, (int) ($cycle->settings['min_peer_responses'] ?? 1));
        $responses = Response::where('review_id', $review->id)->where('status', 'submitted')->get();
        $trace = []; $sum = Decimal::of(0); $sumW = Decimal::of(0);
        foreach (['manager', 'self', 'skip', 'peer'] as $type) {
            $w = Num::in($weights[$type] ?? 0);
            $set = $responses->where('reviewer_type', $type)->filter(fn ($r) => $r->score !== null);
            if (Decimal::isZero($w)) { if ($set->isNotEmpty()) $trace[] = ['step' => 'reviewer', 'type' => $type, 'weight' => '0.00', 'included' => false, 'note' => 'This reviewer type carries no weight in the template.']; continue; }
            if ($set->isEmpty()) { $trace[] = ['step' => 'reviewer', 'type' => $type, 'weight' => Num::round($w, 2), 'included' => false, 'note' => 'No submitted response; weight is redistributed.']; continue; }
            if ($type === 'peer' && $set->count() < $minPeers) { $trace[] = ['step' => 'reviewer', 'type' => $type, 'weight' => Num::round($w, 2), 'included' => false, 'responses' => $set->count(), 'note' => 'Fewer than '.$minPeers.' peer responses; not counted to protect reviewers.']; continue; }
            $score = Decimal::div(Decimal::sum($set->map(fn ($r) => Num::in($r->score))), $set->count(), 6);
            $sum = $sum->plus(Decimal::mul($w, $score)); $sumW = $sumW->plus(Num::d($w));
            $trace[] = ['step' => 'reviewer', 'type' => $type, 'weight' => Num::round($w, 2), 'included' => true, 'responses' => $set->count(), 'score' => Num::round($score, 3)];
        }
        if ($sumW->isZero()) return [null, $trace];
        $score = Num::round(Decimal::div($sum, $sumW, 8), 3);
        $trace[] = ['step' => 'combined', 'formula' => 'sum(reviewer weight x score) / sum(included reviewer weights)', 'weights_total' => Num::round($sumW, 2), 'score' => $score];

        return [$score, $trace];
    }

    /** Label of the scale point nearest to the score (ties round up). */
    public function label(RatingScale $scale, string $score): ?string
    {
        $best = null; $bestDiff = null;
        foreach ($scale->labels as $l) {
            $diff = Decimal::of(Num::in($l['value']))->minus(Num::in($score))->abs();
            if ($best === null || $diff->isLessThan($bestDiff) || ($diff->isEqualTo($bestDiff) && Decimal::cmp(Num::in($l['value']), Num::in($best['value'])) > 0)) { $best = $l; $bestDiff = $diff; }
        }

        return $best['label'] ?? null;
    }
}
