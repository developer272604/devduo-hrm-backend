<?php

use App\Http\Controllers\Attendance\AttendanceReadController;
use App\Http\Controllers\Attendance\Operations\OperationsController as Ops;
use Illuminate\Support\Facades\Route;

Route::get('attendance/team/today', [AttendanceReadController::class, 'team'])->middleware('permission:attendance.team.view');
Route::get('attendance/command-center', [AttendanceReadController::class, 'commandCenter'])->middleware('permission:attendance.manage|attendance.process|attendance.reports.view');

Route::get('attendance/settings', [Ops::class, 'settings'])->middleware('permission:attendance.policy.manage|settings.manage');
Route::get('attendance/settings/{section}', [Ops::class, 'settings'])->middleware('permission:attendance.policy.manage|settings.manage');
Route::put('attendance/settings/{section}', [Ops::class, 'updateSettings'])->middleware('permission:attendance.policy.manage|settings.manage');

Route::get('attendance/me/summary', [Ops::class, 'me'])->middleware('permission:attendance.view');
Route::get('attendance/team/summary', [Ops::class, 'teamSummary'])->middleware('permission:attendance.team.view');
Route::get('attendance/dashboard', [Ops::class, 'dashboard'])->middleware('permission:attendance.view|attendance.team.view');
Route::get('attendance/health', [Ops::class, 'health'])->middleware('permission:attendance.manage|attendance.process');
Route::get('attendance/roster-gaps', [Ops::class, 'rosterGaps'])->middleware('permission:attendance.manage|attendance.roster.manage|attendance.reports.view');

Route::get('attendance/payroll-readiness', [Ops::class, 'readiness'])->middleware('permission:attendance.manage|attendance.process|attendance.reports.view');
Route::get('attendance/payroll-output', [Ops::class, 'output'])->middleware('permission:attendance.manage|attendance.process|attendance.reports.view');

Route::get('attendance/search', [Ops::class, 'search'])->middleware('permission:attendance.view|attendance.team.view|attendance.reports.view|attendance.manage');
Route::get('attendance/reports', [Ops::class, 'catalogue'])->middleware('permission:attendance.reports.view');
Route::get('attendance/reports/{report}', [Ops::class, 'report'])->middleware('permission:attendance.reports.view');
Route::post('attendance/reports/{report}/exports', [Ops::class, 'export'])->middleware(['permission:attendance.export', 'throttle:10,1']);
Route::get('attendance/exports', [Ops::class, 'exports'])->middleware('permission:attendance.export');
Route::get('attendance/exports/{uuid}/download', [Ops::class, 'download'])->middleware('permission:attendance.export');

Route::get('attendance/views', [Ops::class, 'views'])->middleware('permission:attendance.reports.view|attendance.manage');
Route::post('attendance/views', [Ops::class, 'saveView'])->middleware('permission:attendance.reports.view|attendance.manage');
Route::put('attendance/views/{uuid}', [Ops::class, 'saveView'])->middleware('permission:attendance.reports.view|attendance.manage');
Route::delete('attendance/views/{uuid}', [Ops::class, 'deleteView'])->middleware('permission:attendance.reports.view|attendance.manage');

require __DIR__.'/requests.php';
