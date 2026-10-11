<?php

namespace App\Http\Controllers\People\Lifecycle;

use App\Domain\People\Lifecycle\InvitationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Unauthenticated acceptance of an invitation. The token is the credential; failures are deliberately indistinguishable. */
class PublicInvitationController extends Controller
{
    public function accept(Request $r, InvitationService $service)
    {
        $data = $r->validate([
            'token' => ['required', 'string', 'max:200'], 'password' => ['required', 'confirmed', 'string', 'max:200', Password::min(12)->letters()->numbers()],
        ]);
        $service->accept($data['token'], '', $data['password']);

        return response()->json(['message' => 'Your account is ready. You can sign in now.'], 201);
    }
}
