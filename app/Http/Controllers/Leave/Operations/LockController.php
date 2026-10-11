<?php

namespace App\Http\Controllers\Leave\Operations;

use App\Domain\Leave\LockLifecycle\LeaveLockService;
use App\Domain\Leave\Models\LeaveLock;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/** Leave locks: list, preview, create, maker-checker unlock. No logic here: LeaveLockService owns it. */
class LockController extends Controller
{
    public function index(LeaveLockService $svc)
    {
        return ['data' => LeaveLock::query()->orderByDesc('id')->limit(100)->get()->map(fn ($l) => $svc->present($l))];
    }

    public function preview(Request $r, LeaveLockService $svc)
    {
        return $svc->preview($r->all());
    }

    public function store(Request $r, LeaveLockService $svc)
    {
        return response()->json(['data' => $svc->present($svc->create($r->user(), $r->all()))], 201);
    }

    public function unlockRequest(Request $r, LeaveLockService $svc, string $uuid)
    {
        return ['data' => $svc->present($svc->requestUnlock(LeaveLock::byUuid($uuid), $r->user(), (string) $r->input('reason', '')))];
    }

    public function unlockApprove(Request $r, LeaveLockService $svc, string $uuid)
    {
        return ['data' => $svc->present($svc->approveUnlock(LeaveLock::byUuid($uuid), $r->user()))];
    }

    public function unlockReject(Request $r, LeaveLockService $svc, string $uuid)
    {
        return ['data' => $svc->present($svc->rejectUnlock(LeaveLock::byUuid($uuid), $r->user(), $r->input('comment')))];
    }
}
