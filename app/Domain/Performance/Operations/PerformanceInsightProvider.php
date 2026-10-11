<?php

namespace App\Domain\Performance\Operations;

use App\Domain\Access\Classification;
use App\Domain\Insights\InsightProvider;
use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Models\Employee;
use App\Domain\Performance\Models\{CalibrationChange, Cycle, Goal, Pip, Response, Review};
use App\Domain\Performance\{PerformanceAccess};
use App\Domain\Performance\Contracts\PerformanceOutcomeProvider;
use App\Domain\Performance\Support\Num;
use App\Domain\Quality\{Finding, QualityRule};
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Action Center insights for performance. Neutral wording; each insight links to Performance and is limited to what the viewer may act on. */
final class PerformanceInsightProvider implements InsightProvider
{
    public function key(): string
    {
        return 'performance';
    }

    public function insights(User $viewer): array
    {
        if (! $viewer->tenant_id || ! in_array('performance', $viewer->tenant->settings['modules'] ?? [], true)) return [];
        $access = app(PerformanceAccess::class);
        $nav = fn (string $href) => [['label' => 'Open performance', 'kind' => 'navigate', 'href' => $href, 'requires_confirmation' => false]];
        $out = []; $today = TenantClock::today();
        $add = function (string $name, string $severity, int $n, string $title, string $desc, string $href) use (&$out, $nav) {
            if ($n > 0) $out[] = ['key' => 'performance.'.$name, 'domain' => 'performance', 'severity' => $severity, 'title' => $title, 'count' => $n, 'description' => $desc, 'actions' => $nav($href), 'sample' => []];
        };
        $openReviews = Review::whereIn('cycle_id', Cycle::where('status', 'open')->select('id'))->where('status', 'in_progress')->select('id');
        $mine = Response::where('reviewer_user_id', $viewer->id)->where('status', 'pending')->whereIn('review_id', $openReviews)->where('due_on', '<', $today)->count();
        $add('my_reviews_overdue', 'warning', $mine, $mine.' review '.($mine === 1 ? 'response is' : 'responses are').' overdue', 'Complete your assigned reviews so the cycle can move on.', '/performance/reviews');
        if ($viewer->allows('performance.view')) {
            $late = Response::whereIn('review_id', Review::whereIn('cycle_id', Cycle::where('status', 'open')->select('id'))->where('status', 'in_progress')->whereIn('employee_id', $access->reachIds($viewer, 'performance.view'))->select('id'))
                ->where('status', 'pending')->where('due_on', '<', $today)->where('reviewer_user_id', '!=', $viewer->id)->count();
            $add('team_reviews_late', 'warning', $late, $late.' review '.($late === 1 ? 'response is' : 'responses are').' overdue in your scope', 'Follow up with reviewers who have not submitted.', '/performance/dashboard');
        }
        if ($viewer->allows('performance.goals.approve')) {
            $n = Goal::where('status', 'pending_approval')->whereIn('owner_employee_id', $access->reachIds($viewer, 'performance.goals.approve'))->whereNotIn('owner_employee_id', $access->own($viewer) ?: [0])->count();
            $add('goals_pending', 'action_required', $n, $n.' '.($n === 1 ? 'goal awaits' : 'goals await').' your approval', 'Approve or return the goal with a reason.', '/performance/goals');
        }
        if ($viewer->allows('performance.calibrate.approve')) {
            $n = CalibrationChange::where('status', 'pending')->where('proposed_by', '!=', $viewer->id)->count();
            $add('calibration_pending', 'action_required', $n, $n.' calibration '.($n === 1 ? 'change awaits' : 'changes await').' an independent decision', 'A different authorized person must decide each rating change.', '/performance/calibration');
        }

        return $out;
    }
}
