<?php

namespace App\Http\Controllers\Leave\Requests;

use App\Domain\Audit\Audit;
use App\Domain\Leave\Boot\RequestsBoot;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Leave\Models\LeaveRequestDocument;
use App\Domain\Leave\Requests\LeaveApprovers;
use App\Domain\Leave\Requests\LeaveSupport;
use App\Domain\Leave\Requests\RequestPlanner;
use App\Domain\Leave\Requests\RequestPresenter;
use App\Domain\Leave\Requests\RequestService;
use App\Domain\People\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RequestController extends LeaveController
{
    public function __construct(LeaveSupport $support, LeaveApprovers $approvers, RequestPresenter $presenter, private RequestService $service, private RequestPlanner $planner)
    {
        parent::__construct($support, $approvers, $presenter);
    }

    /** scope=mine (default) | team (leave.team.view, data scope) | all (leave.manage, data scope). Filters: status, from, to, employee. */
    public function index(Request $r)
    {
        $user = $r->user();
        $scopeName = $r->input('scope', 'mine');
        $q = LeaveRequest::query()->orderByDesc('start_date')->orderByDesc('id');
        if ($scopeName === 'mine') {
            $q->whereIn('employee_id', $this->support->scope()->ownEmployeeIds($user) ?: [0]);
        } else {
            $permission = $scopeName === 'all' ? 'leave.manage' : 'leave.team.view';
            abort_unless($user->allows($permission), 403, 'You do not have access to that view.');
            $this->support->scope()->constrain($q, $user, $permission, 'leave_requests.employee_id');
        }
        if ($status = $r->input('status')) {
            $q->whereIn('status', is_string($status) ? explode(',', $status) : (array) $status);
        }
        if ($from = $r->input('from')) {
            $q->where('end_date', '>=', $from);
        }
        if ($to = $r->input('to')) {
            $q->where('start_date', '<=', $to);
        }
        if ($e = $r->input('employee')) {
            $q->whereIn('employee_id', Employee::query()->where('uuid', $e)->pluck('id'));
        }

        return response()->json($this->paged($q->paginate($this->perPage($r)), fn (LeaveRequest $x) => $this->presenter->summary($x, $user)));
    }

    public function preview(Request $r)
    {
        $employee = $this->subject($r);
        $plan = $this->planner->plan($r->user(), $employee, $r->all(), $r->filled('modifies') ? $this->request((string) $r->input('modifies')) : null, count($this->files($r)));

        return response()->json(['data' => $this->planner->preview($plan, $r->user())]);
    }

    public function store(Request $r)
    {
        $employee = $this->subject($r);
        [$request, $created] = $this->service->submit($r->user(), $employee, $r->except(['documents']), $this->idempotencyKey($r), $this->files($r));

        return response()->json(['data' => $this->presenter->detail($request, $r->user()), 'duplicate' => ! $created], $created ? 201 : 200);
    }

    public function show(Request $r, string $uuid)
    {
        $request = $this->request($uuid);
        $this->visible($r, $request);

        return response()->json(['data' => $this->presenter->detail($request, $r->user())]);
    }

    public function withdraw(Request $r, string $uuid)
    {
        $request = $this->request($uuid);
        $this->visible($r, $request);

        return response()->json(['data' => $this->presenter->detail($this->service->withdraw($r->user(), $request, $r->input('comment')), $r->user())]);
    }

    /** Body: dates[] (optional, partial cancellation), reason (required unless the employee cancels their own leave). */
    public function cancel(Request $r, string $uuid)
    {
        $request = $this->request($uuid);
        $this->visible($r, $request);
        $r->validate(['dates' => 'nullable|array|max:366', 'dates.*' => 'date_format:Y-m-d', 'reason' => 'nullable|string|max:1000']);

        return response()->json(['data' => $this->presenter->detail($this->service->cancel($r->user(), $request, $r->input('dates'), $r->input('reason')), $r->user())]);
    }

    public function modify(Request $r, string $uuid)
    {
        $request = $this->request($uuid);
        $this->visible($r, $request);
        $new = $this->service->modify($r->user(), $request, $r->except(['documents']), $this->idempotencyKey($r), $this->files($r));

        return response()->json(['data' => $this->presenter->detail($new, $r->user())], 201);
    }

    public function addDocument(Request $r, string $uuid)
    {
        $request = $this->request($uuid);
        $this->visible($r, $request);
        $r->validate(['file' => 'required|file', 'kind' => 'nullable|string|max:40']);
        $doc = $this->service->addDocument($r->user(), $request, $r->file('file'), $r->input('kind'));

        return response()->json(['data' => ['uuid' => $doc->uuid, 'kind' => $doc->kind, 'filename' => $doc->filename, 'sensitive' => (bool) $doc->is_sensitive]], 201);
    }

    /** Medical documents: only the employee and users with leave.medical.view (or opted-in managers). */
    public function document(Request $r, string $uuid, string $document)
    {
        $request = $this->request($uuid);
        $employee = $this->visible($r, $request);
        $doc = LeaveRequestDocument::query()->where('request_id', $request->id)->where('uuid', $document)->firstOrFail();
        abort_unless(! $doc->is_sensitive || $this->presenter->canSeeSensitive($r->user(), $employee), 404, 'That document was not found.');
        Audit::record('document_viewed', 'leave', 'request:'.$request->uuid, ['document' => $doc->uuid, 'sensitive' => (bool) $doc->is_sensitive]);

        return Storage::disk(RequestsBoot::DISK)->download($doc->path, $doc->filename);
    }
}
