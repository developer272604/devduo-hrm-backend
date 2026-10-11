<?php
namespace App\Domain\Operations;

use App\Domain\Access\DataScope;
use App\Domain\Assets\Models\Asset;
use App\Domain\Expenses\Models\{ExpenseClaim, ExpenseFxRate};
use App\Domain\Insights\InsightProvider;
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Travel\Models\{TravelAdvance, TravelReconciliation, TravelRequest};
use App\Models\User;
use Carbon\CarbonImmutable;

/** Action Center signals for assets, expenses and travel. Counts only what the viewer may act on, and never the viewer's own requests. */
final class OperationsInsightProvider implements InsightProvider {
    public function key(): string { return 'operations'; }
    public function insights(User $viewer): array {
        if (! $viewer->tenant_id) return [];
        $modules = $viewer->tenant->settings['modules'] ?? []; $scope = app(DataScope::class); $own = $scope->ownEmployeeIds($viewer) ?: [0]; $out = []; $today = TenantClock::today();
        $add = function (string $name, string $severity, int $n, string $title, string $desc, string $href) use (&$out) {
            if ($n > 0) $out[] = ['key' => 'operations.'.$name, 'domain' => 'operations', 'severity' => $severity, 'title' => $title, 'count' => $n, 'description' => $desc,
                'actions' => [['label' => 'Open', 'kind' => 'navigate', 'href' => $href, 'requires_confirmation' => false]], 'sample' => []];
        };
        $tenantWide = fn (string $p) => $viewer->allows($p) && $scope->hasTenantWide($viewer, $p);
        if (in_array('expenses', $modules, true)) {
            if ($tenantWide('expenses.fx.approve')) { $n = ExpenseFxRate::where('is_override', true)->where('status', 'pending')->where('requested_by', '!=', $viewer->id)->count(); $add('fx_overrides_pending', 'action_required', $n, $n.' FX rate '.($n === 1 ? 'override awaits' : 'overrides await').' your approval', 'Approve or reject with a reason.', '/expenses/rules'); }
            if ($tenantWide('expenses.manage')) { $n = ExpenseClaim::where('status', 'submitted')->where('escalated', true)->whereNotIn('employee_id', $own)->count(); $add('escalated_claims', 'action_required', $n, $n.' escalated expense '.($n === 1 ? 'claim needs' : 'claims need').' review', 'Policy rules escalated these claims to finance.', '/expenses'); }
        }
        if (in_array('travel', $modules, true)) {
            if ($viewer->allows('travel.approve')) {
                $ids = $scope->constrain(Employee::query()->select('id'), $viewer, 'travel.approve');
                $n = TravelAdvance::where('status', 'requested')->whereIn('employee_id', $ids)->whereNotIn('employee_id', $own)->count(); $add('advances_pending', 'action_required', $n, $n.' travel advance '.($n === 1 ? 'request awaits' : 'requests await').' your decision', 'Approve or reject within your scope.', '/travel');
            }
            if ($tenantWide('travel.manage')) {
                $n = TravelRequest::where('status', 'approved')->where('ends_on', '<', $today)->whereNotIn('employee_id', $own)->whereNotIn('id', TravelReconciliation::query()->select('travel_request_id'))->count();
                $add('reconciliation_due', 'warning', $n, $n.' finished '.($n === 1 ? 'trip needs' : 'trips need').' reconciliation', 'Link claims and advances, then close the trip.', '/travel');
            }
        }
        if (in_array('assets', $modules, true) && $tenantWide('assets.manage')) {
            $n = Asset::whereNotNull('warranty_until')->whereBetween('warranty_until', [$today, CarbonImmutable::parse($today)->addDays(30)->toDateString()])->whereNotIn('status', ['disposed', 'lost'])->count();
            $add('warranty_expiring', 'info', $n, $n.' '.($n === 1 ? 'asset warranty ends' : 'asset warranties end').' within 30 days', 'Plan renewals or replacements.', '/assets');
        }
        return $out;
    }
}
