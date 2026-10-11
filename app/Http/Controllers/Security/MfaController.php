<?php

namespace App\Http\Controllers\Security;

use App\Domain\Security\MfaService;
use App\Models\User;
use Illuminate\Http\Request;

final class MfaController
{
    public function __construct(private MfaService $mfa) {}

    public function status(Request $request)
    {
        return $this->mfa->status($request->user());
    }

    public function enroll(Request $request)
    {
        return response()->json($this->mfa->beginEnrollment($request->user()), 201);
    }

    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:16']);

        return ['recovery_codes' => $this->mfa->confirm($request->user(), $data['code']), 'message' => 'Store these recovery codes now. They are shown only once.'];
    }

    public function regenerate(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:16']);

        return ['recovery_codes' => $this->mfa->regenerateRecoveryCodes($request->user(), $data['code'])];
    }

    public function disable(Request $request)
    {
        $data = $request->validate(['password' => 'required|string|max:255', 'code' => 'required|string|max:16']);
        $this->mfa->disable($request->user(), $data['password'], $data['code']);

        return response()->noContent();
    }

    public function adminReset(Request $request, int $user)
    {
        $target = User::where('tenant_id', $request->user()->tenant_id)->findOrFail($user);
        $this->mfa->adminReset($request->user(), $target);

        return response()->noContent();
    }
}
