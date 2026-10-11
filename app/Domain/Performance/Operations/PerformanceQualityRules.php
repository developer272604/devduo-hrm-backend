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

/** Read-only data-quality rules (identifiers only, no review content). */
final class PerformanceQualityRules
{
    /** @return array<int,QualityRule> */
    public static function all(): array
    {
        return [
            self::rule('performance_review_no_reviewer', 'Pending review responses without a reviewer login', function (int $t) {
                foreach (DB::table('performance_review_responses')->where('tenant_id', $t)->where('status', 'pending')->whereNull('reviewer_user_id')
                    ->whereIn('review_id', DB::table('performance_reviews')->where('tenant_id', $t)->where('status', 'in_progress')->select('id'))->get(['id', 'uuid', 'reviewer_type']) as $r)
                    yield new Finding(null, 'resp:'.$r->id, 'A '.$r->reviewer_type.' review has no reviewer who can sign in', 'Assign a reviewer with a login (response '.$r->uuid.').', 'action_required', ['performance_response', $r->id], [], Classification::HR_CONFIDENTIAL);
            }),
            self::rule('performance_review_no_manager', 'Reviews without a manager', function (int $t) {
                foreach (DB::table('performance_reviews')->where('tenant_id', $t)->where('status', 'in_progress')->whereNull('manager_employee_id')->get(['id', 'uuid']) as $r)
                    yield new Finding(null, 'rev:'.$r->id, 'A review in progress has no manager assigned', 'Assign a manager to review '.$r->uuid.'.', 'warning', ['performance_review', $r->id], [], Classification::HR_CONFIDENTIAL);
            }),
            self::rule('performance_goal_weights', 'Individual goal weights do not total 100', function (int $t) {
                $rows = DB::table('performance_goals')->where('tenant_id', $t)->where('level', 'individual')->where('status', 'active')->whereNotNull('owner_employee_id')->get(['owner_employee_id', 'cycle_id', 'weight'])->groupBy(fn ($g) => $g->owner_employee_id.':'.($g->cycle_id ?? 0));
                foreach ($rows as $key => $group) {
                    $total = \App\Domain\Payroll\Support\Decimal::sum($group->map(fn ($g) => Num::in($g->weight)));
                    if (! $total->isEqualTo(100)) yield new Finding(null, 'gw:'.$key, 'Active goal weights total '.Num::round($total, 2).' instead of 100', 'Adjust the weights so they total 100 for the cycle.', 'info', ['employee', (int) $group->first()->owner_employee_id], [], Classification::MANAGER_SENSITIVE);
                }
            }),
            self::rule('performance_goal_owner_inactive', 'Active goals owned by people who left', function (int $t) {
                foreach (DB::table('performance_goals')->join('employees', 'employees.id', '=', 'performance_goals.owner_employee_id')->where('performance_goals.tenant_id', $t)->where('performance_goals.status', 'active')
                    ->whereIn('employees.status', ['separated', 'inactive', 'archived'])->get(['performance_goals.id', 'performance_goals.uuid']) as $g)
                    yield new Finding(null, 'go:'.$g->id, 'An active goal belongs to someone who has left', 'Reassign or close goal '.$g->uuid.'.', 'warning', ['performance_goal', $g->id], [], Classification::MANAGER_SENSITIVE);
            }),
            self::rule('performance_pip_overdue', 'Improvement plans past their end date', function (int $t) {
                foreach (DB::table('performance_pips')->where('tenant_id', $t)->where('status', 'active')->where('end_date', '<', TenantClock::today())->get(['id', 'uuid']) as $p)
                    yield new Finding(null, 'pip:'.$p->id, 'An active improvement plan is past its end date', 'Close or extend plan '.$p->uuid.' with a recorded outcome.', 'warning', ['performance_pip', $p->id], [], Classification::HIGHLY_SENSITIVE);
            }),
        ];
    }

    private static function rule(string $key, string $label, \Closure $scan): QualityRule
    {
        return new class($key, $label, $scan) implements QualityRule {
            public function __construct(private string $key, private string $label, private \Closure $scanner) {}

            public function key(): string { return $this->key; }

            public function category(): string { return 'performance'; }

            public function label(): string { return $this->label; }

            public function scan(int $tenantId): iterable { return ($this->scanner)($tenantId); }
        };
    }
}
