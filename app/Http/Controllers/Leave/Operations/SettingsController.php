<?php

namespace App\Http\Controllers\Leave\Operations;

use App\Domain\Leave\Support\LeaveSettingsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function show(LeaveSettingsService $settings): array
    {
        return ['data' => $settings->show()];
    }

    public function update(Request $request, LeaveSettingsService $settings): array
    {
        return ['data' => $settings->update($request->all())];
    }
}
