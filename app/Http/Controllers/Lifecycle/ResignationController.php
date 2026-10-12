<?php

namespace App\Http\Controllers\Lifecycle;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\Lifecycle\Models\Resignation;
use App\Domain\Lifecycle\CaseService;
use App\Domain\Work\TaskService;
use App\Models\User;
use App\Domain\People\Lifecycle\SeparationService;
use App\Domain\People\Models\{Employee, SeparationRecord};
use App\Domain\Tenancy\TenantClock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ResignationController
{
    private function own(Request $r): array { return app(DataScope::class)->ownEmployeeIds($r->user()); }

    public function employments(Request $r)
    {
        return Employee::whereIn('id', $this->own($r))->whereIn('status', ['active', 'probation', 'suspended', 'long_leave'])->with('person')->get()->map(fn ($e) => ['uuid' => $e->uuid, 'name' => $e->person?->display_name, 'employee_number' => $e->employee_number]);
    }

    public function mine(Request $r)
    {
        return Resignation::whereIn('employee_id', $this->own($r))->orderByDesc('id')->paginate(25)->through(fn ($x) => $this->present($x, $r));
    }

    public function index(Request $r)
    {
        return app(DataScope::class)->constrain(Resignation::query(), $r->user(), 'people.separate', 'lifecycle_resignations.employee_id')->orderByDesc('id')->paginate(25)->through(fn ($x) => $this->present($x, $r));
    }

    public function store(Request $r)
    {
        $d = $r->validate(['employee_uuid' => 'required|uuid', 'requested_last_day' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:4000', 'confirmed' => 'required|accepted']);
        return DB::transaction(function () use ($r, $d) {
            $e = Employee::whereIn('id', $this->own($r))->where('uuid', $d['employee_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless(in_array($e->status, ['active', 'probation', 'suspended', 'long_leave'], true), 422, 'This employment cannot request resignation.');
            abort_if($d['requested_last_day'] < max(TenantClock::today($e->timezone), $e->joined_on->toDateString()), 422, 'Choose a last working day on or after today and your joining date.');
            abort_if(Resignation::where('employee_id', $e->id)->where('status', 'pending')->exists() || SeparationRecord::where('employee_id', $e->id)->where('status', 'initiated')->exists(), 409, 'An active resignation or separation already exists.');
            $x = Resignation::create(['employee_id' => $e->id, 'requested_by' => $r->user()->id, 'requested_last_day' => $d['requested_last_day'], 'reason' => $d['reason'], 'status' => 'pending']);
            Audit::record('resignation.requested', 'lifecycle', $x->uuid, ['employee' => $e->uuid]);
            $this->reviewTasks($x);
            return response()->json($this->present($x, $r), 201);
        });
    }

    public function withdraw(Request $r, string $uuid)
    {
        $d = $r->validate(['reason' => 'required|string|max:4000', 'confirmed' => 'required|accepted']);
        $x = Resignation::whereIn('employee_id', $this->own($r))->where('uuid', $uuid)->firstOrFail();
        return DB::transaction(function () use ($r, $x, $d) {
            $e = Employee::whereKey($x->employee_id)->lockForUpdate()->firstOrFail();
            $x = Resignation::whereKey($x->id)->lockForUpdate()->firstOrFail();
            if (in_array($x->status, ['withdrawn', 'withdrawal_pending'], true)) return $this->present($x, $r);
            abort_unless(in_array($x->status, ['pending', 'approved'], true), 409, 'This request cannot be withdrawn.');
            if ($x->status === 'approved') $this->openSeparation($x, $e);
            $x->update(['status' => $x->status === 'pending' ? 'withdrawn' : 'withdrawal_pending', 'withdrawal_reason' => $d['reason']]);
            Audit::record('resignation.withdrawal_requested', 'lifecycle', $x->uuid, ['status' => $x->status]);
            app(TaskService::class)->completeBySource('lifecycle', 'resignation:'.$x->uuid);
            if ($x->status === 'withdrawal_pending') $this->reviewTasks($x);
            return $this->present($x, $r);
        });
    }

    public function decide(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => 'required|in:approve,reject', 'expected_status' => 'required|in:pending,withdrawal_pending', 'note' => 'required|string|max:4000']);
        $scope = app(DataScope::class);
        $x = $scope->constrain(Resignation::query(), $r->user(), 'people.separate', 'lifecycle_resignations.employee_id')->where('uuid', $uuid)->firstOrFail();
        abort_if($x->requested_by === $r->user()->id || in_array($x->employee_id, $this->own($r)), 403, 'Another HR reviewer must decide your request.');
        return DB::transaction(function () use ($r, $x, $d) {
            $e = Employee::whereKey($x->employee_id)->lockForUpdate()->firstOrFail();
            $x = Resignation::whereKey($x->id)->lockForUpdate()->firstOrFail();
            abort_unless($x->status === $d['expected_status'], 409, 'The request has changed. Refresh before deciding.');
            $approve = $d['decision'] === 'approve';
            if ($x->status === 'pending') {
                if ($approve) {
                    abort_if($x->requested_last_day->toDateString() < TenantClock::today($e->timezone), 422, 'The proposed date has passed. Reject this request and ask for a new date.');
                    $separation = app(SeparationService::class)->initiate($r->user(), $e, ['type' => 'resignation', 'last_working_date' => $x->requested_last_day->toDateString(), 'reason' => 'Approved resignation request '.$x->uuid]);
                    $x->separation_record_id = $separation->id;
                }
                $x->status = $approve ? 'approved' : 'rejected';
            } else {
                $this->openSeparation($x, $e);
                if ($approve) app(SeparationService::class)->withdraw($r->user(), $e, 'Approved withdrawal of resignation request '.$x->uuid);
                $x->status = $approve ? 'withdrawn' : 'approved';
            }
            $x->fill(['decided_by' => $r->user()->id, 'decided_at' => now(), 'decision_note' => $d['note']])->save();
            Audit::record('resignation.decided', 'lifecycle', $x->uuid, ['from' => $d['expected_status'], 'decision' => $d['decision'], 'status' => $x->status]);
            app(TaskService::class)->completeBySource('lifecycle', 'resignation:'.$x->uuid);
            return $this->present($x, $r);
        });
    }

    private function reviewTasks(Resignation $x): void
    {
        $scope = app(DataScope::class);
        foreach (User::whereIn('id', CaseService::usersWith($x->tenant_id, 'people.separate'))->get() as $reviewer) {
            if ($reviewer->id === $x->requested_by || in_array($x->employee_id, $scope->ownEmployeeIds($reviewer)) || !$scope->canAccess($reviewer, $x->employee_id, 'people.separate')) continue;
            app(TaskService::class)->createOnce(['assignee_id' => $reviewer->id, 'title' => $x->status === 'withdrawal_pending' ? 'Review resignation withdrawal' : 'Review resignation request', 'description' => 'Open the scoped resignation review queue to decide this request.', 'source_module' => 'lifecycle', 'source_entity' => 'resignation:'.$x->uuid, 'priority' => 'high', 'due_date' => $x->requested_last_day->toDateString(), 'action' => '/lifecycle/resignation-review']);
        }
    }

    private function openSeparation(Resignation $x, Employee $e): void
    {
        abort_unless($e->status === 'notice_period' && SeparationRecord::whereKey($x->separation_record_id)->where('employee_id', $e->id)->where('status', 'initiated')->exists(), 409, 'The linked separation is no longer open. Contact HR.');
    }

    private function present(Resignation $x, Request $r): array
    {
        $e = Employee::with('person')->findOrFail($x->employee_id);
        $linkedStatus = $x->separation_record_id ? SeparationRecord::whereKey($x->separation_record_id)->value('status') : null;
        $status = in_array($linkedStatus, ['withdrawn', 'completed'], true) ? $linkedStatus : $x->status;
        return ['uuid' => $x->uuid, 'employee_name' => $e->person?->display_name, 'requested_last_day' => $x->requested_last_day->toDateString(), 'status' => $status, 'reason' => $x->reason, 'withdrawal_reason' => $x->withdrawal_reason, 'decision_note' => $x->decision_note,
            'can_decide' => $r->user()->allows('people.separate') && app(DataScope::class)->canAccess($r->user(), $e, 'people.separate') && !in_array($e->id, $this->own($r)) && $x->requested_by !== $r->user()->id && in_array($status, ['pending', 'withdrawal_pending'], true),
            'can_withdraw' => in_array($e->id, $this->own($r)) && ($status === 'pending' || ($status === 'approved' && $e->status === 'notice_period'))];
    }
}
