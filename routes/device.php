<?php

// Attendance device ingestion routes (prefix api/device/v1, middleware device + throttle). Owned by the capture workstream.

use App\Http\Controllers\Attendance\Capture\DeviceIngestController;
use Illuminate\Support\Facades\Route;

Route::post('events', [DeviceIngestController::class, 'events']);
Route::post('heartbeat', [DeviceIngestController::class, 'heartbeat']);
Route::post('sync-complete', [DeviceIngestController::class, 'syncComplete']);
