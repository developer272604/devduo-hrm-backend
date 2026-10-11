<?php

// Attendance routes: devices, capture, geofences, imports (inside prefix v1, module:attendance). Owned by the capture workstream.

use App\Http\Controllers\Attendance\Capture\DeviceController;
use App\Http\Controllers\Attendance\Capture\GeofenceController;
use App\Http\Controllers\Attendance\Capture\ImportController;
use App\Http\Controllers\Attendance\Capture\MyAttendanceController;
use App\Http\Controllers\Attendance\Capture\PunchController;
use Illuminate\Support\Facades\Route;

Route::get('attendance/me/status', [MyAttendanceController::class, 'status'])->middleware('permission:attendance.view');
Route::post('attendance/me/punch', [MyAttendanceController::class, 'punch'])->middleware(['permission:attendance.view', 'throttle:30,1']);
Route::get('attendance/me/punches', [MyAttendanceController::class, 'punches'])->middleware('permission:attendance.view');

Route::get('attendance/punches', [PunchController::class, 'index'])->middleware('permission:attendance.manage|attendance.team.view');
Route::post('attendance/punches', [PunchController::class, 'store'])->middleware('permission:attendance.adjust');
Route::post('attendance/punches/{uuid}/void', [PunchController::class, 'void'])->middleware('permission:attendance.adjust');
Route::post('attendance/punches/{uuid}/verify', [PunchController::class, 'verify'])->middleware('permission:attendance.adjust');
Route::get('attendance/punches/{uuid}/photo', [PunchController::class, 'photo']);

Route::middleware('permission:attendance.devices.manage')->group(function () {
    Route::get('attendance/geofences', [GeofenceController::class, 'index']);
    Route::post('attendance/geofences', [GeofenceController::class, 'store']);
    Route::get('attendance/geofences/{uuid}', [GeofenceController::class, 'show']);
    Route::put('attendance/geofences/{uuid}', [GeofenceController::class, 'update']);
    Route::delete('attendance/geofences/{uuid}', [GeofenceController::class, 'destroy']);

    Route::get('attendance/device-providers', [DeviceController::class, 'providers']);
    Route::get('attendance/devices', [DeviceController::class, 'index']);
    Route::post('attendance/devices', [DeviceController::class, 'store']);
    Route::get('attendance/devices/{uuid}', [DeviceController::class, 'show']);
    Route::put('attendance/devices/{uuid}', [DeviceController::class, 'update']);
    Route::post('attendance/devices/{uuid}/rotate-token', [DeviceController::class, 'rotateToken']);
    Route::post('attendance/devices/{uuid}/revoke-token', [DeviceController::class, 'revokeToken']);
    Route::post('attendance/devices/{uuid}/test', [DeviceController::class, 'test']);
    Route::post('attendance/devices/{uuid}/sync', [DeviceController::class, 'sync']);
    Route::get('attendance/devices/{uuid}/sync-runs', [DeviceController::class, 'syncRuns']);
    Route::get('attendance/devices/{uuid}/unmapped', [DeviceController::class, 'unmapped']);
    Route::get('attendance/devices/{uuid}/mappings', [DeviceController::class, 'mappings']);
    Route::post('attendance/devices/{uuid}/mappings', [DeviceController::class, 'storeMapping']);
    Route::put('attendance/devices/{uuid}/mappings/{id}', [DeviceController::class, 'updateMapping']);
    Route::delete('attendance/devices/{uuid}/mappings/{id}', [DeviceController::class, 'endMapping']);
});

Route::middleware('permission:attendance.import')->group(function () {
    Route::get('attendance/imports/template', [ImportController::class, 'template']);
    Route::get('attendance/imports', [ImportController::class, 'index']);
    Route::post('attendance/imports', [ImportController::class, 'store'])->middleware('throttle:20,1');
    Route::get('attendance/imports/{uuid}', [ImportController::class, 'show']);
    Route::put('attendance/imports/{uuid}/mapping', [ImportController::class, 'mapping']);
    Route::post('attendance/imports/{uuid}/validate', [ImportController::class, 'validateImport']);
    Route::get('attendance/imports/{uuid}/rows', [ImportController::class, 'rows']);
    Route::post('attendance/imports/{uuid}/commit', [ImportController::class, 'commit']);
    Route::post('attendance/imports/{uuid}/retry', [ImportController::class, 'retry']);
    Route::get('attendance/imports/{uuid}/errors.csv', [ImportController::class, 'errors']);
});
