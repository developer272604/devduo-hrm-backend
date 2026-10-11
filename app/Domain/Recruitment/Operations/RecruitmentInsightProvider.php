<?php

namespace App\Domain\Recruitment\Operations;

use App\Domain\Insights\InsightProvider;
use App\Domain\Recruitment\HiringScope;
use App\Domain\Recruitment\Models\{Handover, Interview, Offer, Panelist, Scorecard};
use App\Models\User;

/** Action Center contribution, scoped by HiringScope. Neutral wording, every insight links to Recruitment. */
final class RecruitmentInsightProvider implements InsightProvider
{
    public function key(): string
    {
        return 'recruitment';
    }

    public function insights(User $viewer): array
    {
        if (!$viewer->tenant_id || !$viewer->allows('recruitment.view')) return [];
        $scope = app(HiringScope::class);
        $nav = [['label' => 'Open recruitment', 'kind' => 'navigate', 'href' => '/recruitment', 'requires_confirmation' => false]];
        $out = [];
        $add = function (string $name, string $severity, int $count, string $title, string $description) use (&$out, $nav) {
            if ($count > 0) $out[] = ['key' => 'recruitment.'.$name, 'domain' => 'recruitment', 'severity' => $severity, 'title' => $title, 'count' => $count, 'description' => $description, 'actions' => $nav, 'sample' => []];
        };
        $today = now('UTC')->toDateString();
        if ($viewer->allows('recruitment.approve')) {
            $n = $scope->requisitions($viewer, 'recruitment.approve')->where('status', 'pending')->where('created_by', '!=', $viewer->id)->count();
            $add('requisitions_pending', 'action_required', $n, $n.' hiring '.($n === 1 ? 'requisition awaits' : 'requisitions await').' your review', 'A different authorized person must approve each requisition.');
        }
        if ($viewer->allows('recruitment.offer.approve')) {
            $n = Offer::where('status', 'pending_approval')->where('created_by', '!=', $viewer->id)->whereIn('application_id', $scope->applications($viewer, 'recruitment.offer.approve')->select('id'))->count();
            $add('offers_pending', 'action_required', $n, $n.' job '.($n === 1 ? 'offer awaits' : 'offers await').' approval', 'Review the offer and approve or return it.');
        }
        if ($viewer->allows('recruitment.handover')) {
            $n = Offer::where('status', 'accepted')->whereNotIn('id', Handover::query()->select('offer_id'))->count();
            $add('handover_waiting', 'warning', $n, $n.' accepted '.($n === 1 ? 'offer has' : 'offers have').' no employee record yet', 'Complete the pre-joiner handover so onboarding can start.');
        }
        if ($viewer->allows('recruitment.manage')) {
            $n = $scope->requisitions($viewer, 'recruitment.manage')->where('status', 'open')->where('target_date', '<', $today)->count();
            $add('requisitions_overdue', 'warning', $n, $n.' open '.($n === 1 ? 'requisition is' : 'requisitions are').' past the target date', 'Review the pipeline or move the target date.');
        }
        $n = Interview::whereIn('id', Panelist::where('user_id', $viewer->id)->select('interview_id'))->where('status', '!=', 'cancelled')->where('starts_at', '<', now('UTC')->format('Y-m-d H:i:s'))
            ->whereNotIn('id', Scorecard::where('user_id', $viewer->id)->select('interview_id'))->count();
        $add('feedback_due', 'info', $n, $n.' interview feedback '.($n === 1 ? 'form needs' : 'forms need').' your input', 'Submit your scorecard while the conversation is fresh.');

        return $out;
    }
}
