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

/** Employee 360 "Performance" section: facts only (latest finalized outcome, active goals). PIPs, feedback, 1:1 notes and review text are never shown here. */
final class PerformanceEmployee360Provider implements Employee360Provider
{
    public function key(): string
    {
        return 'performance';
    }

    public function sections(User $viewer, Employee $employee, array $relation): array
    {
        $access = app(PerformanceAccess::class);
        $self = $access->isSelf($viewer, $employee) && $viewer->allows('performance.self');
        if (! $self && ! $access->reaches($viewer, $employee, 'performance.view')) return [];
        $items = [];
        if ($o = app(PerformanceOutcomeProvider::class)->latest($employee->id)) {
            $items[] = ['key' => 'latest_review', 'label' => 'Latest finalized review', 'value' => $o['final_rating'] ?? $o['final_score'], 'detail' => $o['cycle_name'], 'date' => substr($o['finalized_at'], 0, 10), 'source' => 'performance'];
        }
        $goals = Goal::where('owner_employee_id', $employee->id)->where('status', 'active')->get();
        if ($goals->count()) {
            $avg = Num::round(\App\Domain\Payroll\Support\Decimal::div(\App\Domain\Payroll\Support\Decimal::sum($goals->map(fn ($g) => Num::in($g->progress))), $goals->count(), 6), 1);
            $items[] = ['key' => 'active_goals', 'label' => 'Active goals', 'value' => $goals->count().' (average progress '.$avg.'%)', 'source' => 'performance'];
        }

        return $items ? [['key' => 'performance', 'title' => 'Performance', 'order' => 85, 'source' => 'performance', 'items' => $items]] : [];
    }
}
