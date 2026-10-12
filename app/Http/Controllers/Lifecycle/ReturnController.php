<?php
namespace App\Http\Controllers\Lifecycle;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Lifecycle\{CaseService, TemplateService};
use App\Domain\Lifecycle\Models\{ReturnRequest, LifecycleCase};
use App\Domain\People\Models\Employee;
use App\Domain\People\Services\StatusService;
use App\Domain\Tenancy\TenantClock;
use App\Domain\Work\TaskService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReturnController
{
    private function scoped(Request $r) { return app(DataScope::class)->constrain(ReturnRequest::query(), $r->user(), 'people.manage', 'lifecycle_returns.employee_id'); }
    private function history(Employee $e) { return DB::table('employee_status_history')->where('tenant_id', $e->tenant_id)->where('employee_id', $e->id)->max('id'); }
    public function employees(Request $r) {
        $r->validate(['q' => 'nullable|string|max:100']);
        return app(DataScope::class)->constrain(Employee::query(), $r->user(), 'people.manage')->where('status', 'long_leave')->whereHas('person', fn ($q) => $q->where('display_name', 'like', '%'.$r->input('q', '').'%'))->with('person')->orderBy('id')->limit(50)->get()->map(fn ($e) => ['uuid' => $e->uuid, 'name' => $e->person?->display_name, 'employee_number' => $e->employee_number]);
    }
    public function index(Request $r) { return $this->scoped($r)->orderByDesc('id')->paginate(25)->through(fn ($x) => $this->present($x, $r)); }
    public function store(Request $r) {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'note' => 'required|string|max:2000']);
        return DB::transaction(function () use ($r, $d) {
            $e = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'people.manage')->where('uuid', $d['employee_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless($e->status === 'long_leave', 409, 'Only an employee on long leave can return.');
            abort_if(ReturnRequest::where('employee_id', $e->id)->where('status', 'pending')->exists(), 409, 'A return request is already pending.');
            $x = ReturnRequest::create(['employee_id' => $e->id, 'requested_by' => $r->user()->id, 'status_history_id' => $this->history($e), 'note' => $d['note'], 'status' => 'pending']);
            Audit::record('return.requested', 'lifecycle', $x->uuid, ['employee' => $e->uuid]);
            $scope = app(DataScope::class);
            foreach (User::whereIn('id', CaseService::usersWith($e->tenant_id, 'people.manage'))->get() as $reviewer) {
                if ($reviewer->id === $x->requested_by || in_array($e->id, $scope->ownEmployeeIds($reviewer)) || !$scope->canAccess($reviewer, $e, 'people.manage')) continue;
                app(TaskService::class)->createOnce(['assignee_id' => $reviewer->id, 'title' => 'Review return-to-work request', 'description' => 'Open the scoped return-to-work queue to review this request.', 'source_module' => 'lifecycle', 'source_entity' => 'return:'.$x->uuid, 'priority' => 'normal', 'action' => '/lifecycle/returns']);
            }
            return response()->json($this->present($x, $r), 201);
        });
    }
    public function decide(Request $r, string $uuid) {
        $d = $r->validate(['decision' => 'required|in:approve,reject', 'note' => 'required|string|max:2000', 'confirmed' => 'required|accepted']);
        $x = $this->scoped($r)->where('uuid', $uuid)->firstOrFail();
        abort_if($x->requested_by === $r->user()->id || in_array($x->employee_id, app(DataScope::class)->ownEmployeeIds($r->user())), 403, 'An independent HR reviewer must decide.');
        return DB::transaction(function () use ($r, $x, $d) {
            $e = Employee::whereKey($x->employee_id)->lockForUpdate()->firstOrFail();
            $x = ReturnRequest::whereKey($x->id)->lockForUpdate()->firstOrFail();
            abort_unless($x->status === 'pending', 409, 'This request was already decided.');
            if ($d['decision'] === 'approve') {
                abort_unless($e->status === 'long_leave' && (string) $this->history($e) === (string) $x->status_history_id, 409, 'Employment status changed. Reject this request and create a new one.');
                $today = TenantClock::today($e->timezone);
                abort_unless(app(TemplateService::class)->resolve('absence_return', $e, [], $today), 422, 'Publish a matching return-to-work checklist before approval.');
                $target = $e->probation_applicable && !$e->confirmation_date && $e->probation_end ? 'probation' : 'active';
                app(StatusService::class)->transition($e, $target, ['effective_date' => $today, 'reason_code' => 'absence_return', 'reason' => 'Approved return-to-work request '.$x->uuid]);
                $case = app(CaseService::class)->generate('absence_return', $e->fresh(), $today, ['source' => 'r:'.$x->uuid]);
                $x->fill(['returned_on' => $today, 'case_id' => $case->id]);
            }
            $x->fill(['status' => $d['decision'] === 'approve' ? 'approved' : 'rejected', 'decided_by' => $r->user()->id, 'decision_note' => $d['note']])->save();
            Audit::record('return.decided', 'lifecycle', $x->uuid, ['decision' => $d['decision'], 'returned_on' => $x->returned_on?->toDateString()]);
            app(TaskService::class)->completeBySource('lifecycle', 'return:'.$x->uuid);
            return $this->present($x, $r);
        });
    }
    private function present(ReturnRequest $x, Request $r): array {
        return ['uuid' => $x->uuid, 'employee_name' => Employee::with('person')->findOrFail($x->employee_id)->person?->display_name, 'note' => $x->note, 'decision_note' => $x->decision_note, 'status' => $x->status, 'returned_on' => $x->returned_on?->toDateString(), 'case_uuid' => $x->case_id ? LifecycleCase::find($x->case_id)?->uuid : null, 'can_decide' => $x->status === 'pending' && $x->requested_by !== $r->user()->id && !in_array($x->employee_id, app(DataScope::class)->ownEmployeeIds($r->user()))];
    }
}
