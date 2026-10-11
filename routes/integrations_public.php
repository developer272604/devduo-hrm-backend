<?php

// Public read-only API v1 (prefix api/public/v1). Authenticated by tenant API client key, not by browser session.

use App\Http\Controllers\Integrations\PublicApiController;
use Illuminate\Support\Facades\Route;

Route::get('employees', [PublicApiController::class, 'employees'])->middleware('api.client:employees:read');
Route::get('departments', [PublicApiController::class, 'departments'])->middleware('api.client:departments:read');
