<?php

use App\Http\Controllers\Attendance\DayReadController;
use App\Http\Controllers\Attendance\PolicyController;
use App\Http\Controllers\Attendance\ProcessingController;
use Illuminate\Support\Facades\Route;

/* Policies, processing, exceptions, locks, daily/calendar/explain APIs, simulator (inside prefix v1, module:attendance). */
$manage = 'permission:attendance.policy.manage';
$read = 'permission:attendance.policy.manage|attendance.manage';
$dayRead = 'permission:attendance.view|attendance.team.view|attendance.manage';
$date = '\d{4}-\d{2}-\d{2}';

Route::get('attendance-policies', [PolicyController::class, 'index'])->middleware($read);
Route::post('attendance-policies', [PolicyController::class, 'store'])->middleware($manage);
Route::get('attendance-policies/resolve', [PolicyController::class, 'resolve'])->middleware($read);
Route::post('attendance-policies/simulate', [PolicyController::class, 'simulate'])->middleware('permission:attendance.simulate');
Route::get('attendance-policy-assignments', [PolicyController::class, 'assignments'])->middleware($read);
Route::post('attendance-policy-assignments', [PolicyController::class, 'storeAssignment'])->middleware($manage);
Route::patch('attendance-policy-assignments/{id}', [PolicyController::class, 'updateAssignment'])->middleware($manage)->whereNumber('id');
Route::delete('attendance-policy-assignments/{id}', [PolicyController::class, 'destroyAssignment'])->middleware($manage)->whereNumber('id');
Route::get('attendance-policies/{uuid}', [PolicyController::class, 'show'])->middleware($read);
Route::patch('attendance-policies/{uuid}', [PolicyController::class, 'update'])->middleware($manage);
Route::post('attendance-policies/{uuid}/versions', [PolicyController::class, 'newVersion'])->middleware($manage);
Route::get('attendance-policies/{uuid}/versions/{version}', [PolicyController::class, 'showVersion'])->middleware($read)->whereNumber('version');
Route::patch('attendance-policies/{uuid}/versions/{version}', [PolicyController::class, 'updateVersion'])->middleware($manage)->whereNumber('version');
Route::post('attendance-policies/{uuid}/versions/{version}/publish', [PolicyController::class, 'publish'])->middleware($manage)->whereNumber('version');
Route::post('attendance-policies/{uuid}/versions/{version}/retire', [PolicyController::class, 'retire'])->middleware($manage)->whereNumber('version');

Route::post('attendance/process', [ProcessingController::class, 'process'])->middleware('permission:attendance.process');
Route::post('attendance/process/preview', [ProcessingController::class, 'preview'])->middleware('permission:attendance.process');
Route::get('attendance/process/runs', [ProcessingController::class, 'runs'])->middleware('permission:attendance.process|attendance.manage');
Route::get('attendance/process/runs/{uuid}', [ProcessingController::class, 'showRun'])->middleware('permission:attendance.process|attendance.manage');

Route::get('attendance/exceptions', [ProcessingController::class, 'exceptions'])->middleware('permission:attendance.manage|attendance.team.view');
Route::post('attendance/exceptions/{uuid}/{action}', [ProcessingController::class, 'exceptionAction'])->middleware('permission:attendance.manage|attendance.approve')->where('action', 'acknowledge|resolve|dismiss|reopen');

Route::get('attendance/locks', [ProcessingController::class, 'locks'])->middleware('permission:attendance.lock|attendance.unlock|attendance.manage');
Route::post('attendance/locks/preview', [ProcessingController::class, 'lockPreview'])->middleware('permission:attendance.lock');
Route::post('attendance/locks/approve-days', [ProcessingController::class, 'approveDays'])->middleware('permission:attendance.lock');
Route::post('attendance/locks', [ProcessingController::class, 'lockStore'])->middleware('permission:attendance.lock');
Route::post('attendance/locks/{uuid}/unlock-request', [ProcessingController::class, 'unlockRequest'])->middleware('permission:attendance.lock|attendance.unlock');
Route::post('attendance/locks/{uuid}/unlock-approve', [ProcessingController::class, 'unlockApprove'])->middleware('permission:attendance.unlock');
Route::post('attendance/locks/{uuid}/unlock-reject', [ProcessingController::class, 'unlockReject'])->middleware('permission:attendance.unlock');

Route::get('attendance/daily', [DayReadController::class, 'daily'])->middleware('permission:attendance.manage|attendance.team.view');
Route::get('attendance/me/days', [DayReadController::class, 'myDays'])->middleware('permission:attendance.view');
Route::get('attendance/days/{employeeUuid}/{date}', [DayReadController::class, 'show'])->middleware($dayRead)->where('date', $date);
Route::get('attendance/days/{employeeUuid}/{date}/reconstruction', [DayReadController::class, 'reconstruction'])->middleware($dayRead)->where('date', $date);
Route::get('attendance/days/{employeeUuid}/{date}/trace', [DayReadController::class, 'trace'])->middleware('permission:attendance.manage')->where('date', $date);
