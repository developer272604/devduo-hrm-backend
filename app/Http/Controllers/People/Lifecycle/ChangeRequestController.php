<?php

namespace App\Http\Controllers\People\Lifecycle;

use App\Domain\Access\DataScope;
use App\Domain\People\Lifecycle\ChangeRequestService;
use App\Domain\People\Models\Employee;
use App\Domain\People\Models\ProfileChangeRequest;
use App\Http\Controllers\People\PeopleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Profile change requests: submit (employee), list/show (own + approvers in scope), approve / reject / cancel. */
class ChangeRequestController extends PeopleController
{
    public function __construct(private ChangeRequestService $service) {}

    public function store(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $data = $r->validate([
            'section' => ['required', Rule::in(ChangeRequestService::SECTIONS)], 'operation' => ['required', Rule::in(ChangeRequestService::OPERATIONS)],
            'target_id' => ['nullable', 'uuid'], 'requested_value' => ['nullable', 'array'], 'effective_date' => ['nullable', 'date_format:Y-m-d'], 'comments' => ['nullable', 'string', 'max:2000'],
        ]);
        $row = $this->service->submit($r->user(), $employee, $data);

        return response()->json($this->service->present($row, $employee->loadMissing('person'), $r->user(), true), 201);
    }

    /** Requests about one employee: their own, or what an approver in scope may see. */
    public function forEmployee(Request $r, string $uuid)
    {
        $employee = $this->employee($r, $uuid);
        $rows = ProfileChangeRequest::query()->where('employee_id', $employee->id)->orderByDesc('id')->limit(100)->get()
            ->filter(fn ($row) => $this->service->canView($r->user(), $row, $employee));
        $employee->loadMissing('person');

        return ['data' => $rows->map(fn ($row) => $this->service->present($row, $employee, $r->user(), false))->values()];
    }

    public function index(Request $r, DataScope $scope)
    {
        $r->validate(['status' => 'nullable|in:pending,approved,rejected,cancelled', 'mine' => 'nullable|boolean', 'employee_uuid' => 'nullable|uuid', 'per_page' => 'nullable|integer|between:5,100']);
        $user = $r->user();
        $query = ProfileChangeRequest::query()->orderByDesc('id');
        $approver = $user->allows('people.changes.approve');
        $own = $scope->ownEmployeeIds($user);
        if (! $approver || $r->boolean('mine')) {
            $query->where(fn ($q) => $q->where('requested_by', $user->id)->orWhereIn('employee_id', $own ?: [0]));
        } else {
            $reach = $scope->constrain(DB::table('employees as e')->where('e.tenant_id', $user->tenant_id), $user, 'people.changes.approve', 'e.id')->select('e.id');
            $query->where(fn ($q) => $q->where('requested_by', $user->id)->orWhereIn('employee_id', $reach));
            if ($hidden = $this->service->hiddenSections($user)) {
                $query->where(fn ($q) => $q->where('requested_by', $user->id)->orWhereNotIn('section', $hidden));
            }
        }
        if ($r->filled('status')) {
            $query->where('status', $r->input('status'));
        }
        if ($r->filled('employee_uuid')) {
            $query->whereIn('employee_id', Employee::query()->where('uuid', $r->input('employee_uuid'))->select('id'));
        }
        $page = $query->paginate($this->perPage($r));
        $employees = Employee::query()->with('person')->whereIn('id', $page->getCollection()->pluck('employee_id')->unique())->get()->keyBy('id');
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->service->present($row, $employees[$row->employee_id], $user, false)));

        return $page;
    }

    public function show(Request $r, string $uuid)
    {
        [$row, $employee] = $this->load($r, $uuid);

        return $this->service->present($row, $employee, $r->user(), true);
    }

    public function approve(Request $r, string $uuid)
    {
        [$row, $employee] = $this->load($r, $uuid);
        $data = $r->validate(['comment' => 'nullable|string|max:2000']);

        return $this->service->present($this->service->approve($r->user(), $row, $data['comment'] ?? null), $employee, $r->user(), true);
    }

    public function reject(Request $r, string $uuid)
    {
        [$row, $employee] = $this->load($r, $uuid);
        $data = $r->validate(['comment' => 'required|string|max:2000'], ['comment.required' => 'Give a reason so the employee understands the decision.']);

        return $this->service->present($this->service->reject($r->user(), $row, $data['comment']), $employee, $r->user(), true);
    }

    public function cancel(Request $r, string $uuid)
    {
        [$row, $employee] = $this->load($r, $uuid);

        return $this->service->present($this->service->cancel($r->user(), $row), $employee, $r->user(), true);
    }

    /** @return array{0:ProfileChangeRequest,1:Employee} 404 for anything the viewer cannot read, so existence is not disclosed */
    private function load(Request $r, string $uuid): array
    {
        abort_unless(Str::isUuid($uuid), 404);
        $row = ProfileChangeRequest::query()->where('uuid', $uuid)->firstOrFail();
        $employee = Employee::query()->with('person')->findOrFail($row->employee_id);
        abort_unless($this->service->canView($r->user(), $row, $employee), 404);

        return [$row, $employee];
    }
}
