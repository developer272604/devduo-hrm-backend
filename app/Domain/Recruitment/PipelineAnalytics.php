<?php

namespace App\Domain\Recruitment;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Counts and time-to-fill, scoped by HiringScope. Decimals are strings; no personal data. */
final class PipelineAnalytics
{
    public function summary(User $actor): array
    {
        $scope = app(HiringScope::class);
        $reqs = $scope->requisitions($actor, 'recruitment.reports.view')->get(['id', 'status', 'openings', 'filled_count', 'opened_at', 'filled_at', 'target_date']);
        $apps = $scope->applications($actor, 'recruitment.reports.view')->get(['id', 'requisition_id', 'stage', 'status', 'source', 'stage_entered_at']);
        $filled = $reqs->filter(fn ($r) => $r->filled_at && $r->opened_at);
        $days = $filled->map(fn ($r) => $r->opened_at->diffInSeconds($r->filled_at) / 86400);
        $offers = DB::table('recruitment_offers')->whereIn('application_id', $apps->pluck('id'))->where('tenant_id', $actor->tenant_id)->pluck('status');
        $answered = $offers->filter(fn ($s) => in_array($s, ['accepted', 'declined'], true))->count();
        $today = now('UTC')->toDateString();
        return [
            'requisitions' => ['by_status' => $reqs->countBy('status')->all(), 'openings' => (int) $reqs->whereIn('status', ['open'])->sum('openings'), 'filled' => (int) $reqs->sum('filled_count'),
                'overdue' => $reqs->where('status', 'open')->filter(fn ($r) => (string) $r->target_date->toDateString() < $today)->count()],
            'pipeline' => ['by_stage' => $apps->where('status', 'active')->countBy('stage')->all(), 'by_status' => $apps->countBy('status')->all(), 'by_source' => $apps->countBy(fn ($a) => $a->source ?: 'unknown')->all()],
            'time_to_fill' => ['filled_requisitions' => $filled->count(), 'average_days' => $days->isEmpty() ? null : number_format($days->avg(), 1, '.', '')],
            'sla' => $this->sla($apps, $reqs),
            'offers' => ['by_status' => $offers->countBy()->all(), 'acceptance_rate' => $answered ? number_format($offers->where(fn ($s) => $s === 'accepted')->count() / $answered * 100, 1, '.', '') : null],
        ];
    }

    /** Stage SLA breaches among active applications and average completed time in stage; integers/decimal strings only. */
    private function sla($apps, $reqs): array
    {
        $pipelines = app(PipelineService::class);
        $reqById = \App\Domain\Recruitment\Models\Requisition::whereIn('id', $apps->pluck('requisition_id')->unique())->get()->keyBy('id');
        $cache = [];
        $now = now()->getTimestamp();
        $byStage = [];
        foreach ($apps->where('status', 'active') as $a) {
            $req = $reqById[$a->requisition_id] ?? null;
            if (! $req) {
                continue;
            }
            $p = $cache[$req->id] ??= $pipelines->resolve($req);
            $stage = $p->stages->firstWhere('code', $a->stage);
            if (in_array($stage?->kind, ['hired', 'rejected'], true)) {
                continue;
            }
            $row = &$byStage[$a->stage];
            $row ??= ['active' => 0, 'breached' => 0];
            $row['active']++;
            if ($now - \Carbon\Carbon::parse($a->stage_entered_at)->getTimestamp() > PipelineService::slaHours($stage) * 3600) {
                $row['breached']++;
            }
            unset($row);
        }
        $seconds = [];
        $counts = [];
        $actions = \App\Domain\Recruitment\Models\ApplicationAction::whereIn('application_id', $apps->pluck('id'))->orderBy('application_id')->orderBy('id')->get(['application_id', 'to_stage', 'status', 'created_at'])->groupBy('application_id');
        foreach ($actions as $list) {
            $open = null;
            foreach ($list as $act) {
                $t = $act->created_at->getTimestamp();
                if ($open) {
                    $seconds[$open[0]] = ($seconds[$open[0]] ?? 0) + max(0, $t - $open[1]);
                    $counts[$open[0]] = ($counts[$open[0]] ?? 0) + 1;
                }
                $open = $act->status === 'active' ? [$act->to_stage, $t] : null;
            }
        }
        $avg = [];
        foreach ($seconds as $stage => $sum) {
            $tenths = intdiv($sum * 20 + $counts[$stage] * 3600, $counts[$stage] * 7200); // rounded tenths of an hour
            $avg[$stage] = intdiv($tenths, 10).'.'.($tenths % 10);
        }
        ksort($byStage);
        ksort($avg);

        return ['by_stage' => $byStage, 'breached_total' => (int) array_sum(array_column($byStage, 'breached')), 'average_hours_in_stage' => $avg];
    }
}
