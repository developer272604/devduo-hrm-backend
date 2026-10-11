<?php

namespace App\Http\Controllers\Security;

use App\Domain\Audit\Audit;
use App\Domain\Security\SessionService;
use App\Models\User;
use Illuminate\Http\Request;

final class SessionController
{
    public function __construct(private SessionService $sessions) {}

    public function index(Request $request)
    {
        return ['data' => $this->sessions->list($request->user(), $request->session()->getId())];
    }

    public function revoke(Request $request, string $uuid)
    {
        $this->sessions->revoke($request->user(), $uuid, $request->session()->getId());

        return response()->noContent();
    }

    public function revokeOthers(Request $request)
    {
        return ['revoked' => $this->sessions->revokeOthers($request->user(), $request->session()->getId())];
    }

    /** Admin forced logout of every session of another user. */
    public function revokeUser(Request $request, int $user)
    {
        $target = User::where('tenant_id', $request->user()->tenant_id)->findOrFail($user);
        $count = $this->sessions->revokeAllFor($target->id, 'admin_forced', $target->id === $request->user()->id ? $request->session()->getId() : null);
        Audit::record('security.session.forced_logout', 'security', 'user:'.$target->id, ['count' => $count]);

        return ['revoked' => $count];
    }
}
