<?php

use App\Http\Controllers\Attendance\Scheduling\RosterController;
use App\Http\Controllers\Attendance\Scheduling\SchedulingConfigController as C;
use Illuminate\Support\Facades\Route;

// Attendance routes: scheduling (inside prefix v1, module:attendance). See docs/api/attendance-scheduling.md.
$read = 'permission:attendance.view|attendance.roster.manage|attendance.policy.manage';
$config = 'permission:attendance.policy.manage';
$edit = 'permission:attendance.roster.manage';

Route::get('shifts', [C::class, 'shiftIndex'])->middleware($read);
Route::post('shifts', [C::class, 'shiftStore'])->middleware($config);
Route::get('shifts/{uuid}', [C::class, 'shiftShow'])->middleware($read);
Route::patch('shifts/{uuid}', [C::class, 'shiftUpdate'])->middleware($config);
Route::delete('shifts/{uuid}', [C::class, 'shiftDestroy'])->middleware($config);

Route::get('work-weeks', [C::class, 'weekIndex'])->middleware($read);
Route::post('work-weeks', [C::class, 'weekStore'])->middleware($config);
Route::get('work-weeks/{uuid}', [C::class, 'weekShow'])->middleware($read);
Route::patch('work-weeks/{uuid}', [C::class, 'weekUpdate'])->middleware($config);
Route::post('work-weeks/{uuid}/versions', [C::class, 'weekVersion'])->middleware($config);
Route::delete('work-weeks/{uuid}', [C::class, 'weekDestroy'])->middleware($config);

Route::get('holiday-calendars', [C::class, 'calendarIndex'])->middleware($read);
Route::post('holiday-calendars', [C::class, 'calendarStore'])->middleware($config);
Route::get('holiday-calendars/{uuid}', [C::class, 'calendarShow'])->middleware($read);
Route::patch('holiday-calendars/{uuid}', [C::class, 'calendarUpdate'])->middleware($config);
Route::delete('holiday-calendars/{uuid}', [C::class, 'calendarDestroy'])->middleware($config);
Route::post('holiday-calendars/{uuid}/copy', [C::class, 'calendarCopy'])->middleware($config);
Route::post('holiday-calendars/{uuid}/generate', [C::class, 'holidayGenerate'])->middleware($config);
Route::post('holiday-calendars/{uuid}/holidays', [C::class, 'holidayStore'])->middleware($config);
Route::patch('holiday-calendars/{uuid}/holidays/{id}', [C::class, 'holidayUpdate'])->middleware($config);
Route::delete('holiday-calendars/{uuid}/holidays/{id}', [C::class, 'holidayDestroy'])->middleware($config);
Route::get('employees/{uuid}/holidays', [C::class, 'employeeHolidays'])->middleware('permission:attendance.view|attendance.team.view|attendance.roster.manage');

Route::get('shift-rotations', [C::class, 'rotationIndex'])->middleware($read);
Route::post('shift-rotations', [C::class, 'rotationStore'])->middleware($config);
Route::get('shift-rotations/{uuid}', [C::class, 'rotationShow'])->middleware($read);
Route::patch('shift-rotations/{uuid}', [C::class, 'rotationUpdate'])->middleware($config);
Route::delete('shift-rotations/{uuid}', [C::class, 'rotationDestroy'])->middleware($config);

foreach (['shift' => 'shift-assignments', 'work_week' => 'work-week-assignments', 'holiday' => 'holiday-calendar-assignments'] as $kind => $path) {
    Route::get($path, [C::class, 'assignmentIndex'])->defaults('kind', $kind)->middleware($edit);
    Route::post($path, [C::class, 'assignmentStore'])->defaults('kind', $kind)->middleware($edit);
    Route::patch($path.'/{id}', [C::class, 'assignmentUpdate'])->defaults('kind', $kind)->middleware($edit);
    Route::delete($path.'/{id}', [C::class, 'assignmentDestroy'])->defaults('kind', $kind)->middleware($edit);
}

Route::get('payroll-groups', [C::class, 'payrollIndex'])->middleware($read);
Route::post('payroll-groups', [C::class, 'payrollSave'])->middleware($config);
Route::patch('payroll-groups/{id}', [C::class, 'payrollSave'])->middleware($config);
Route::delete('payroll-groups/{id}', [C::class, 'payrollDestroy'])->middleware($config);

Route::get('attendance/compliance', [C::class, 'complianceShow'])->middleware($read);
Route::put('attendance/compliance', [C::class, 'complianceSave'])->middleware($config);
Route::post('attendance/impact-preview', [C::class, 'impact'])->middleware('permission:attendance.roster.manage|attendance.policy.manage');

Route::get('rosters', [RosterController::class, 'index'])->middleware($edit);
Route::post('rosters', [RosterController::class, 'store'])->middleware($edit);
Route::get('rosters/{uuid}', [RosterController::class, 'show'])->middleware($edit);
Route::patch('rosters/{uuid}', [RosterController::class, 'update'])->middleware($edit);
Route::get('rosters/{uuid}/grid', [RosterController::class, 'grid'])->middleware($edit);
Route::post('rosters/{uuid}/entries', [RosterController::class, 'setEntry'])->middleware($edit);
Route::post('rosters/{uuid}/entries/clear', [RosterController::class, 'clearEntry'])->middleware($edit);
Route::post('rosters/{uuid}/entries/discard', [RosterController::class, 'discard'])->middleware($edit);
Route::post('rosters/{uuid}/bulk-assign', [RosterController::class, 'bulk'])->middleware($edit);
Route::post('rosters/{uuid}/copy', [RosterController::class, 'copy'])->middleware($edit);
Route::post('rosters/{uuid}/validate', [RosterController::class, 'validateRoster'])->middleware($edit);
Route::post('rosters/{uuid}/publish', [RosterController::class, 'publish'])->middleware('permission:attendance.roster.publish');
Route::post('rosters/{uuid}/unpublish', [RosterController::class, 'unpublish'])->middleware('permission:attendance.roster.publish');
Route::get('rosters/{uuid}/changes', [RosterController::class, 'changes'])->middleware($edit);
Route::get('rosters/{uuid}/history', [RosterController::class, 'history'])->middleware($edit);

Route::get('attendance/me/schedule', [RosterController::class, 'mySchedule'])->middleware('permission:attendance.view');
Route::get('attendance/team/schedule', [RosterController::class, 'teamSchedule'])->middleware('permission:attendance.team.view');
Route::get('shift-swaps', [RosterController::class, 'swapIndex'])->middleware('permission:attendance.view');
Route::post('shift-swaps', [RosterController::class, 'swapStore'])->middleware('permission:attendance.view');
Route::get('shift-swaps/{uuid}', [RosterController::class, 'swapShow'])->middleware('permission:attendance.view');
Route::post('shift-swaps/{uuid}/respond', [RosterController::class, 'swapRespond'])->middleware('permission:attendance.view');
Route::post('shift-swaps/{uuid}/withdraw', [RosterController::class, 'swapWithdraw'])->middleware('permission:attendance.view');
Route::post('shift-swaps/{uuid}/decision', [RosterController::class, 'swapDecide'])->middleware('permission:attendance.approve|attendance.manage');
