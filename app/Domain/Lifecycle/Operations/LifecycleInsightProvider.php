<?php

namespace App\Domain\Lifecycle\Operations;

use App\Domain\Access\DataScope;
use App\Domain\Insights\InsightProvider;
use App\Domain\Lifecycle\Models\{LifecycleCase, LifecycleTask, Move};
use App\Domain\Tenancy\TenantClock;
use App\Models\User;

/** Action Center contribution: own overdue tasks, overdue/blocked lifecycle work in scope, moves awaiting an independent approver. */
final class LifecycleInsightProvider implements InsightProvider
{
    public function key(): string
    {
        return 'lifecycle';
    }

    public function insights(User $viewer): array
    {
        if (! $viewer->tenant_id) return [];
        $nav = [['label' => 'Open lifecycle', 'kind' => 'navigate', 'href' => '/lifecycle', 'requires_confirmation' => false]];
        $out = [];
        $add = function (string $name, string $severity, int $count, string $title, string $description) use (&$out, $nav) {
            if ($count > 0) $out[] = ['key' => 'lifecycle.'.$name, 'domain' => 'lifecycle', 'severity' => $severity, 'title' => $title, 'count' => $count, 'description' => $description, 'actions' => $nav, 'sample' => []];
        };
        $today = TenantClock::today();
        $open = fn () => LifecycleTask::where('lifecycle_tasks.status', 'pending')->whereIn('lifecycle_tasks.case_id', LifecycleCase::where('status', 'open')->select('id'));
        $mine = $open()->where('owner_user_id', $viewer->id)->where('due_date', '<', $today)->count();
        $add('my_overdue', 'action_required', $mine, $mine.' lifecycle '.($mine === 1 ? 'task is' : 'tasks are').' overdue for you', 'Complete or hand over the checklist items assigned to you.');
        if ($viewer->allows('lifecycle.view')) {
            $scope = app(DataScope::class);
            $n = $scope->constrain($open()->join('lifecycle_cases', 'lifecycle_cases.id', '=', 'lifecycle_tasks.case_id'), $viewer, 'lifecycle.view', 'lifecycle_cases.employee_id')
                ->where('lifecycle_tasks.due_date', '<', $today)->count();
            $add('overdue', 'warning', $n, $n.' lifecycle '.($n === 1 ? 'task is' : 'tasks are').' overdue', 'Follow up with the owners or reassign.');
            $b = $scope->constrain($open()->join('lifecycle_cases', 'lifecycle_cases.id', '=', 'lifecycle_tasks.case_id')->where('lifecycle_cases.kind', 'offboarding'), $viewer, 'lifecycle.view', 'lifecycle_cases.employee_id')
                ->where('lifecycle_tasks.mandatory', true)->whereNotNull('lifecycle_tasks.clearance_dept')->distinct()->count('lifecycle_cases.id');
            $add('clearance_blocked', 'warning', $b, $b.' '.($b === 1 ? 'exit is' : 'exits are').' waiting on clearance', 'Final settlement cannot start until clearance items are done.');
        }
        if ($viewer->allows('lifecycle.moves.approve')) {
            $n = Move::where('status', 'pending')->where('requested_by', '!=', $viewer->id)->count();
            $add('moves_pending', 'action_required', $n, $n.' internal '.($n === 1 ? 'move awaits' : 'moves await').' your approval', 'A different authorized person must approve each move.');
        }

        return $out;
    }
}
