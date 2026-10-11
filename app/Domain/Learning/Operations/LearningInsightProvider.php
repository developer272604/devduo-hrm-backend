<?php

namespace App\Domain\Learning\Operations;

use App\Domain\Insights\InsightProvider;
use App\Domain\Learning\LearningAccess;
use App\Domain\Learning\Models\{Assignment, EmployeeCert, Enrolment};
use App\Domain\Tenancy\TenantClock;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Action Center insights for learning; counts only what the viewer may act on. */
final class LearningInsightProvider implements InsightProvider
{
    public function key(): string
    {
        return 'learning';
    }

    public function insights(User $viewer): array
    {
        if (! $viewer->tenant_id || ! in_array('learning', $viewer->tenant->settings['modules'] ?? [], true)) return [];
        $access = app(LearningAccess::class); $today = TenantClock::today(); $out = [];
        $add = function (string $name, string $severity, int $n, string $title, string $desc, string $href) use (&$out) {
            if ($n > 0) $out[] = ['key' => 'learning.'.$name, 'domain' => 'learning', 'severity' => $severity, 'title' => $title, 'count' => $n, 'description' => $desc,
                'actions' => [['label' => 'Open learning', 'kind' => 'navigate', 'href' => $href, 'requires_confirmation' => false]], 'sample' => []];
        };
        $own = $access->own($viewer);
        if ($own) {
            $n = Assignment::whereIn('employee_id', $own)->where('status', 'assigned')->where('due_on', '<', $today)->count();
            $add('my_overdue', 'warning', $n, $n.' mandatory '.($n === 1 ? 'course is' : 'courses are').' overdue', 'Complete the training to stay compliant.', '/learning/my');
        }
        if ($viewer->allows('learning.view')) {
            $ids = $access->reachIds($viewer, 'learning.view')->pluck('id')->all() ?: [0];
            $n = Assignment::whereIn('employee_id', $ids)->whereNotIn('employee_id', $own ?: [0])->where('status', 'assigned')->where('due_on', '<', $today)->count();
            $add('team_overdue', 'action_required', $n, $n.' overdue mandatory training '.($n === 1 ? 'assignment' : 'assignments').' in your scope', 'Follow up with the people concerned.', '/learning/compliance');
            $n = EmployeeCert::whereIn('employee_id', $ids)->where('status', 'active')->whereNotNull('expires_on')->where('expires_on', '<=', CarbonImmutable::parse($today)->addDays(30)->toDateString())->count();
            $add('certs_expiring', 'warning', $n, $n.' '.($n === 1 ? 'certificate expires' : 'certificates expire').' within 30 days', 'Plan renewals before they lapse.', '/learning/certificates');
        }
        if ($viewer->allows('learning.enrol.approve')) {
            $n = Enrolment::where('status', 'pending_approval')->whereIn('employee_id', $access->reachIds($viewer, 'learning.enrol.approve')->pluck('id')->all() ?: [0])->whereNotIn('employee_id', $own ?: [0])->count();
            $add('enrolments_pending', 'action_required', $n, $n.' course '.($n === 1 ? 'enrolment awaits' : 'enrolments await').' your decision', 'Approve or reject with a reason.', '/learning/team');
        }

        return $out;
    }
}
