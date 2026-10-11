<?php

use App\Http\Controllers\Payroll\Runs\InputController;
use App\Http\Controllers\Payroll\Runs\RunController;
use Illuminate\Support\Facades\Route;

/*
 * Workstream B (inside /api/v1/payroll): runs, run employees, inputs, adjustments, validations, results, approvals, lock / reopen.
 * Documented in docs/api/payroll-runs.md. Every route: permission middleware + tenant (global scope) + data scope in the services.
 */
Route::get('runs', [RunController::class, 'index'])->middleware('permission:payroll.view');
Route::post('runs/preview', [RunController::class, 'preview'])->middleware('permission:payroll.run');
Route::post('runs', [RunController::class, 'store'])->middleware('permission:payroll.run');
Route::get('runs/{uuid}', [RunController::class, 'show'])->middleware('permission:payroll.view');
Route::get('runs/{uuid}/employees', [RunController::class, 'employees'])->middleware('permission:payroll.view');
Route::patch('runs/{uuid}/employees/{employeeUuid}', [RunController::class, 'setEmployee'])->middleware('permission:payroll.run');
Route::post('runs/{uuid}/collect-inputs', [RunController::class, 'collectInputs'])->middleware('permission:payroll.run');
Route::post('runs/{uuid}/calculate', [RunController::class, 'calculate'])->middleware('permission:payroll.run');
Route::get('runs/{uuid}/progress', [RunController::class, 'progress'])->middleware('permission:payroll.view');
Route::post('runs/{uuid}/validate', [RunController::class, 'validateRun'])->middleware('permission:payroll.run');
Route::get('runs/{uuid}/validations', [RunController::class, 'validations'])->middleware('permission:payroll.view');
Route::post('validations/{issueUuid}/waive', [RunController::class, 'waive'])->middleware('permission:payroll.approve');
Route::get('runs/{uuid}/totals', [RunController::class, 'totals'])->middleware('permission:payroll.results.view');
Route::get('runs/{uuid}/results/{employeeUuid}', [RunController::class, 'result'])->middleware('permission:payroll.results.view');
Route::post('runs/{uuid}/results/{employeeUuid}/verify', [RunController::class, 'verify'])->middleware('permission:payroll.results.view');
Route::post('runs/{uuid}/submit', [RunController::class, 'submit'])->middleware('permission:payroll.run');
Route::post('runs/{uuid}/approve', [RunController::class, 'approve'])->middleware('permission:payroll.approve');
Route::post('runs/{uuid}/reject', [RunController::class, 'reject'])->middleware('permission:payroll.approve');
Route::post('runs/{uuid}/lock', [RunController::class, 'lock'])->middleware('permission:payroll.lock');
Route::post('runs/{uuid}/reopen-request', [RunController::class, 'reopenRequest'])->middleware('permission:payroll.unlock');
Route::post('runs/{uuid}/reopen-decision', [RunController::class, 'reopenDecision'])->middleware('permission:payroll.unlock.approve');
Route::post('runs/{uuid}/cancel', [RunController::class, 'cancel'])->middleware('permission:payroll.run');

Route::get('inputs', [InputController::class, 'index'])->middleware('permission:payroll.inputs.manage|payroll.inputs.approve');
Route::get('inputs/imports', [InputController::class, 'importHistory'])->middleware('permission:payroll.inputs.manage');
Route::post('inputs/imports/preview', [InputController::class, 'previewImport'])->middleware('permission:payroll.inputs.manage');
Route::get('inputs/imports/{uuid}', [InputController::class, 'showImport'])->middleware('permission:payroll.inputs.manage');
Route::post('inputs/imports/{uuid}/commit', [InputController::class, 'commitImport'])->middleware('permission:payroll.inputs.manage');
Route::post('inputs/imports/{uuid}/retry', [InputController::class, 'retryImport'])->middleware('permission:payroll.inputs.manage');
Route::post('inputs', [InputController::class, 'store'])->middleware('permission:payroll.inputs.manage');
Route::post('inputs/{uuid}/decision', [InputController::class, 'decide'])->middleware('permission:payroll.inputs.approve');
Route::delete('inputs/{uuid}', [InputController::class, 'void'])->middleware('permission:payroll.inputs.manage');

Route::get('adjustments', [InputController::class, 'adjustments'])->middleware('permission:payroll.adjust|payroll.adjust.approve');
Route::post('adjustments', [InputController::class, 'storeAdjustment'])->middleware('permission:payroll.adjust');
Route::post('adjustments/{uuid}/decision', [InputController::class, 'decideAdjustment'])->middleware('permission:payroll.adjust.approve');
Route::post('adjustments/{uuid}/reverse', [InputController::class, 'reverseAdjustment'])->middleware('permission:payroll.adjust');
