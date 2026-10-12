<?php

namespace App\Http\Controllers\Lifecycle;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Lifecycle\{CaseService, TemplateService};
use App\Domain\Lifecycle\Models\{ContractRenewal, LifecycleCase};
use App\Domain\People\Models\Employee;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ContractRenewalController
{
    private function scoped(Request $r)
    {
        return app(DataScope::class)->constrain(ContractRenewal::query(), $r->user(), 'people.manage', 'lifecycle_contract_renewals.employee_id');
    }

    public function employees(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100']);
        $today = TenantClock::today();

        return app(DataScope::class)->constrain(Employee::query(), $r->user(), 'people.manage')
            ->whereIn('status', ['active', 'probation', 'suspended', 'long_leave'])
            ->whereNotNull('contract_end')
            ->whereDate('contract_end', '>=', $today)
            ->whereHas('person', fn ($q) => $q->where('display_name', 'like', '%'.$r->input('q', '').'%'))
            ->with('person')->orderBy('contract_end')->limit(50)->get()
            ->map(fn ($e) => ['uuid' => $e->uuid, 'name' => $e->person?->display_name, 'employee_number' => $e->employee_number, 'contract_end' => $e->contract_end?->toDateString()]);
    }

    public function index(Request $r)
    {
        return $this->scoped($r)->orderByDesc('id')->paginate(25)->through(fn ($x) => $this->present($x, $r));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'new_contract_end' => 'required|date_format:Y-m-d', 'note' => 'required|string|max:2000']);

        return DB::transaction(function () use ($r, $d) {
            $employee = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'people.manage')->where('uuid', $d['employee_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless(in_array($employee->status, ['active', 'probation', 'suspended', 'long_leave'], true), 409, 'Only current employments can be renewed.');
            abort_unless($employee->contract_end, 422, 'This employee does not have a contract end date.');
            abort_unless($d['new_contract_end'] > $employee->contract_end->toDateString(), 422, 'The new contract end must be after the current contract end.');
            abort_if(ContractRenewal::where('employee_id', $employee->id)->where('status', 'pending')->exists(), 409, 'A contract renewal request is already pending.');
            $renewal = ContractRenewal::create(['employee_id' => $employee->id, 'current_contract_end' => $employee->contract_end->toDateString(), 'new_contract_end' => $d['new_contract_end'], 'note' => $d['note'], 'requested_by' => $r->user()->id, 'status' => 'pending']);
            Audit::record('contract_renewal.requested', 'lifecycle', $renewal->uuid, ['employee' => $employee->uuid, 'new_contract_end' => $d['new_contract_end']]);
            $this->reviewTasks($renewal);

            return response()->json($this->present($renewal, $r), 201);
        });
    }

    public function decide(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => 'required|in:approve,reject', 'note' => 'required|string|max:2000', 'confirmed' => 'required|accepted']);
        $renewal = $this->scoped($r)->where('uuid', $uuid)->firstOrFail();
        abort_if($renewal->requested_by === $r->user()->id || in_array($renewal->employee_id, app(DataScope::class)->ownEmployeeIds($r->user())), 403, 'An independent HR reviewer must decide.');

        return DB::transaction(function () use ($r, $renewal, $d) {
            $employee = Employee::whereKey($renewal->employee_id)->lockForUpdate()->firstOrFail();
            $renewal = ContractRenewal::whereKey($renewal->id)->lockForUpdate()->firstOrFail();
            abort_unless($renewal->status === 'pending', 409, 'This request was already decided.');
            if ($d['decision'] === 'approve') {
                abort_unless($employee->contract_end && $employee->contract_end->toDateString() === $renewal->current_contract_end?->toDateString(), 409, 'The contract end date changed. Reject this request and create a new one.');
                abort_unless(app(TemplateService::class)->resolve('contract_renewal', $employee, [], $renewal->new_contract_end->toDateString()), 422, 'Publish a matching contract-renewal checklist before approval.');
                $employee->update(['contract_end' => $renewal->new_contract_end->toDateString()]);
                $case = app(CaseService::class)->generate('contract_renewal', $employee->fresh(), $renewal->new_contract_end->toDateString(), ['source' => 'cr:'.$renewal->uuid]);
                $renewal->case_id = $case->id;
            }
            $renewal->fill(['status' => $d['decision'] === 'approve' ? 'approved' : 'rejected', 'decided_by' => $r->user()->id, 'decided_at' => now(), 'decision_note' => $d['note']])->save();
            Audit::record('contract_renewal.decided', 'lifecycle', $renewal->uuid, ['decision' => $d['decision'], 'new_contract_end' => $renewal->new_contract_end->toDateString()]);
            app(TaskService::class)->completeBySource('lifecycle', 'contract_renewal:'.$renewal->uuid);

            return $this->present($renewal, $r);
        });
    }

    private function reviewTasks(ContractRenewal $renewal): void
    {
        $scope = app(DataScope::class);
        foreach (User::whereIn('id', CaseService::usersWith($renewal->tenant_id, 'people.manage'))->get() as $reviewer) {
            if ($reviewer->id === $renewal->requested_by || in_array($renewal->employee_id, $scope->ownEmployeeIds($reviewer)) || ! $scope->canAccess($reviewer, $renewal->employee_id, 'people.manage')) {
                continue;
            }
            app(TaskService::class)->createOnce(['assignee_id' => $reviewer->id, 'title' => 'Review contract renewal', 'description' => 'Open the scoped contract-renewal queue to review this request.', 'source_module' => 'lifecycle', 'source_entity' => 'contract_renewal:'.$renewal->uuid, 'priority' => 'normal', 'due_date' => $renewal->current_contract_end?->toDateString(), 'action' => '/lifecycle/contract-renewals']);
        }
    }

    private function present(ContractRenewal $renewal, Request $r): array
    {
        $employee = Employee::with('person')->findOrFail($renewal->employee_id);

        return ['uuid' => $renewal->uuid, 'employee_name' => $employee->person?->display_name, 'current_contract_end' => $renewal->current_contract_end?->toDateString(), 'new_contract_end' => $renewal->new_contract_end->toDateString(),
            'note' => $renewal->note, 'decision_note' => $renewal->decision_note, 'status' => $renewal->status, 'case_uuid' => $renewal->case_id ? LifecycleCase::find($renewal->case_id)?->uuid : null,
            'can_decide' => $renewal->status === 'pending' && $renewal->requested_by !== $r->user()->id && ! in_array($renewal->employee_id, app(DataScope::class)->ownEmployeeIds($r->user()))];
    }
}
