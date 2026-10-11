<?php

namespace App\Http\Controllers\Attendance\Requests;

use App\Domain\Attendance\Models\Regularization;
use App\Domain\Attendance\Requests\RegularizationService;
use App\Domain\Attendance\Requests\RequestSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class RegularizationController extends RequestController
{
    public function index(Request $r): JsonResponse
    {
        $user = $r->user();
        $q = Regularization::query()->with('employee.person')->orderByDesc('id');
        if ($r->input('scope') === 'team') {
            $this->support->scope()->constrain($q, $user, 'attendance.team.view', 'attendance_regularizations.employee_id');
            $own = $this->support->scope()->ownEmployeeIds($user);
            $own && $q->whereNotIn('employee_id', $own);
        } else {
            $own = $this->support->scope()->ownEmployeeIds($user);
            $q->whereIn('employee_id', $own ?: [0]);
        }
        if ($r->filled('status')) {
            $q->where('status', (string) $r->input('status'));
        }
        if ($r->filled('employee')) {
            $e = $this->support->employee($user, (string) $r->input('employee'), 'attendance.team.view');
            $q->where('employee_id', $e->id);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            $r->filled($key) && $q->where('attendance_date', $op, (string) $r->input($key));
        }

        return response()->json($this->paged($q->paginate($this->perPage($r)), fn ($x) => $this->presenter->regularization($x, $user)));
    }

    public function store(Request $r, RegularizationService $service): JsonResponse
    {
        $r->validate(['attachment' => 'nullable|file']);
        $employee = $this->subject($r, 'attendance.manage');
        [$request, $created] = $service->submit($r->user(), $employee, $r->only(['date', 'type', 'requested_in', 'requested_out', 'reason', 'comments']), $r->file('attachment'), $this->idempotencyKey($r));

        return response()->json(['data' => $this->presenter->regularization($request, $r->user(), true), 'idempotent_replay' => ! $created], $created ? 201 : 200);
    }

    public function show(Request $r, string $uuid): JsonResponse
    {
        $request = $this->find($r, $uuid);

        return response()->json(['data' => $this->presenter->regularization($request, $r->user(), true)]);
    }

    public function approve(Request $r, string $uuid, RegularizationService $service): JsonResponse
    {
        $request = $service->decide($r->user(), $this->find($r, $uuid, false), 'approve', $r->input('comment'));

        return response()->json(['data' => $this->presenter->regularization($request, $r->user(), true)]);
    }

    public function reject(Request $r, string $uuid, RegularizationService $service): JsonResponse
    {
        $request = $service->decide($r->user(), $this->find($r, $uuid, false), 'reject', $r->input('comment'));

        return response()->json(['data' => $this->presenter->regularization($request, $r->user(), true)]);
    }

    public function withdraw(Request $r, string $uuid, RegularizationService $service): JsonResponse
    {
        $request = $service->withdraw($r->user(), $this->find($r, $uuid));

        return response()->json(['data' => $this->presenter->regularization($request, $r->user(), true)]);
    }

    public function attachment(Request $r, string $uuid)
    {
        $request = $this->find($r, $uuid);
        abort_unless($request->attachment_path && Storage::disk(RequestSupport::DISK)->exists($request->attachment_path), 404, 'There is no attachment on this request.');

        return Storage::disk(RequestSupport::DISK)->download($request->attachment_path, 'regularization-'.$request->attendance_date.'.'.pathinfo($request->attachment_path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff']);
    }

    /** $checkView = false for decision endpoints, where the service's own canDecide check applies (a delegate may not see the employee). */
    private function find(Request $r, string $uuid, bool $checkView = true): Regularization
    {
        $request = Regularization::query()->with('employee.person')->where('uuid', $uuid)->firstOrFail();
        abort_unless($this->mayView($r, $request->employee, 'regularization', $request->attendance_date, (int) $request->current_step), 404, 'That request was not found.');

        return $request;
    }
}
