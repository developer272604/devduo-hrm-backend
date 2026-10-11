<?php

namespace App\Http\Controllers\Workflow;

use App\Domain\Access\DataScope;
use App\Domain\Audit\Audit;
use App\Domain\People\Models\Employee;
use App\Domain\Workflow\Models\Definition;
use App\Domain\Workflow\Models\Version;
use App\Domain\Workflow\WorkflowService;
use App\Domain\Workflow\WorkflowEventCatalog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WorkflowController
{
    public function __construct(private WorkflowService $service) {}

    public function definitions(Request $r)
    {
        $this->service->manager($r->user());

        return Definition::latest('id')->paginate(25);
    }

    public function definition(Request $r, string $uuid)
    {
        $this->service->manager($r->user());
        $d = Definition::where('uuid', $uuid)->firstOrFail();

        return ['definition' => $d, 'versions' => Version::where('definition_id', $d->id)->orderByDesc('number')->get()];
    }

    public function save(Request $r, ?string $uuid = null)
    {
        $d = $r->validate(['name' => 'required|string|max:150', 'spec' => 'required|array:steps,trigger', 'revision' => ($uuid ? 'required' : 'nullable').'|integer|min:1']);

        return response()->json($this->service->draft($r->user(), $d, $uuid), $uuid ? 200 : 201);
    }

    public function publish(Request $r, string $uuid)
    {
        $d = $r->validate(['revision' => 'required|integer|min:1']);

        return $this->service->publish($r->user(), $uuid, $d['revision']);
    }

    public function simulate(Request $r)
    {
        $d = $r->validate(['spec' => 'required|array:steps,trigger', 'employee_uuid' => 'required|uuid']);

        return $this->service->simulate($r->user(), $d['spec'], $d['employee_uuid']);
    }

    public function start(Request $r)
    {
        $d = $r->validate(['definition_uuid' => 'required|uuid', 'employee_uuid' => 'required|uuid', 'idempotency_key' => 'required|string|max:100']);

        return response()->json($this->service->present($this->service->start($r->user(), $d['definition_uuid'], $d['employee_uuid'], $d['idempotency_key']), $r->user(), true), 201);
    }

    public function instances(Request $r)
    {
        $q = $this->service->visible($r->user());
        if ($r->filled('status')) {
            $r->validate(['status' => 'in:running,waiting,paused,failed,dead_letter,completed,cancelled,rejected']);
            $q->where('status', $r->status);
        }

        return $q->latest('id')->paginate(25)->through(fn ($i) => $this->service->present($i, $r->user()));
    }

    public function show(Request $r, string $uuid)
    {
        return $this->service->present($this->service->visible($r->user())->where('uuid', $uuid)->firstOrFail(), $r->user(), true);
    }

    public function decide(Request $r, string $uuid)
    {
        $d = $r->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'required|string|max:1000']);

        return $this->service->present($this->service->decide($r->user(), $uuid, $d['decision'], $d['reason']), $r->user(), true);
    }

    public function delegate(Request $r, string $uuid)
    {
        $d = $r->validate(['from_user_id' => 'required|integer', 'to_user_id' => 'required|integer|different:from_user_id', 'reason' => 'required|string|max:1000']);

        return $this->service->present($this->service->delegate($r->user(), $uuid, $d['from_user_id'], $d['to_user_id'], $d['reason']), $r->user(), true);
    }

    public function delegationOptions(Request $r, string $uuid)
    {
        return ['data' => $this->service->delegationOptions($r->user(), $uuid)];
    }

    public function control(Request $r, string $uuid)
    {
        $d = $r->validate(['action' => 'required|in:pause,resume,cancel,retry', 'reason' => 'required|string|max:1000']);

        return $this->service->present($this->service->control($r->user(), $uuid, $d['action'], $d['reason']), $r->user(), true);
    }

    public function options(Request $r)
    {
        $this->service->manager($r->user());
        $r->validate(['search' => 'nullable|string|max:100']);
        $q = app(DataScope::class)->constrain(Employee::query(), $r->user(), 'people.view');
        abort_unless($r->user()->allows('people.view'), 403);
        if ($r->filled('search')) {
            $q->where('employee_number', 'like', '%'.$r->search.'%');
        }

        return ['employees' => $q->limit(100)->get(['uuid', 'employee_number']),
            'users' => User::where('tenant_id', $r->user()->tenant_id)->where('active', true)->whereHas('roles.permissions', fn ($q) => $q->where('key', 'workflows.act'))->limit(100)->get(['id', 'name']),
            'event_types' => WorkflowEventCatalog::options()];
    }

    public function deliveries(Request $r)
    {
        $this->service->manager($r->user());

        return DB::table('workflow_deliveries')->where('tenant_id', $r->user()->tenant_id)
            ->whereIn('status', ['pending', 'retry', 'dead_letter'])->latest('id')->paginate(25, ['id', 'status', 'attempts', 'next_at', 'failure_code']);
    }

    public function retryDelivery(Request $r, int $id)
    {
        $this->service->manager($r->user());
        $r->validate(['reason' => 'required|string|max:1000']);

        return DB::transaction(function () use ($r, $id) {
            $q = DB::table('workflow_deliveries')->where('tenant_id', $r->user()->tenant_id)->where('id', $id);
            $row = (clone $q)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_unless(in_array($row->status, ['retry', 'dead_letter']), 422, 'Only failed deliveries can be retried.');
            $q->update(['status' => 'retry', 'attempts' => 0, 'next_at' => now(), 'updated_at' => now()]);
            Audit::record('workflow.delivery.retry', 'workflows', 'delivery:'.$id, ['reason' => $r->reason]);

            return ['status' => 'retry'];
        });
    }
}
