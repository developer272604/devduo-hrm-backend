<?php
namespace App\Domain\Operations;
use App\Domain\Assets\AssetService;
use App\Domain\Expenses\ExpenseService;
use App\Domain\Travel\TravelService;
use App\Domain\People\Employee360\Employee360Provider;
use App\Domain\People\Models\Employee;
use App\Models\User;

final class OperationsEmployee360Provider implements Employee360Provider {
    public function key(): string { return 'operations'; }
    public function sections(User $viewer, Employee $employee, array $relation): array {
        $modules = $viewer->tenant->settings['modules'] ?? []; $items = [];
        if (in_array('assets', $modules) && ($viewer->allows('assets.self') || $viewer->allows('assets.manage'))) {
            foreach (app(AssetService::class)->visible($viewer)->where('employee_id', $employee->id)->latest('id')->limit(5)->get() as $a) {
                $items[] = ['key' => 'asset:'.$a->uuid, 'label' => $a->identifier, 'value' => $a->status, 'date' => $a->updated_at->toDateString(), 'source' => 'assets'];
            }
        }
        if (in_array('expenses', $modules)) {
            foreach (app(ExpenseService::class)->visible($viewer)->where('employee_id', $employee->id)->latest('id')->limit(5)->get() as $c) {
                $items[] = ['key' => 'claim:'.$c->uuid, 'label' => 'Expense claim', 'value' => $c->status.($c->escalated ? ' (escalated)' : ''), 'date' => $c->created_at->toDateString(), 'source' => 'expenses'];
            }
        }
        if (in_array('travel', $modules)) {
            foreach (app(TravelService::class)->visible($viewer)->where('employee_id', $employee->id)->latest('id')->limit(5)->get() as $t) {
                $items[] = ['key' => 'trip:'.$t->uuid, 'label' => 'Travel request', 'value' => $t->status, 'date' => $t->created_at->toDateString(), 'source' => 'travel'];
                $advances = \App\Domain\Travel\Models\TravelAdvance::where('travel_request_id', $t->id)->whereIn('status', ['requested', 'approved'])->count();
                if ($advances) $items[] = ['key' => 'advances:'.$t->uuid, 'label' => 'Travel advances open', 'value' => (string) $advances, 'date' => $t->created_at->toDateString(), 'source' => 'travel'];
                if ($t->status === 'approved' && ! \App\Domain\Travel\Models\TravelReconciliation::where('travel_request_id', $t->id)->exists() && $t->ends_on->toDateString() < \App\Domain\Tenancy\TenantClock::today()) {
                    $items[] = ['key' => 'reconcile:'.$t->uuid, 'label' => 'Trip reconciliation', 'value' => 'pending', 'date' => $t->ends_on->toDateString(), 'source' => 'travel'];
                }
            }
        }
        return $items ? [['key' => 'operations', 'title' => 'Assets, expenses and travel', 'order' => 90, 'source' => 'operations', 'items' => $items]] : [];
    }
}
