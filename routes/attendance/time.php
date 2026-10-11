<?php

use App\Http\Controllers\Attendance\AttendanceReadController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/processing.php';

Route::get('attendance/me',[AttendanceReadController::class,'mine'])->middleware('permission:attendance.view');
Route::post('attendance/punch',[AttendanceReadController::class,'punch'])->middleware(['permission:attendance.view','throttle:30,1']);
Route::get('attendance/days',[AttendanceReadController::class,'days'])->middleware('permission:attendance.view|attendance.team.view|attendance.manage|attendance.reports.view');
Route::get('attendance/days/{uuid}',[AttendanceReadController::class,'show'])->middleware('permission:attendance.view|attendance.team.view|attendance.manage|attendance.reports.view');
