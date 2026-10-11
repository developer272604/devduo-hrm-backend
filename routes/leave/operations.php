<?php

use App\Http\Controllers\Leave\Operations\InsightController;
use App\Http\Controllers\Leave\Operations\LockController;
use App\Http\Controllers\Leave\Operations\PayrollController;
use App\Http\Controllers\Leave\Operations\ReportController;
use App\Http\Controllers\Leave\Operations\SettingsController;
use App\Http\Controllers\Leave\Operations\TransferController;
use Illuminate\Support\Facades\Route;

/*
 * Leave workstream D - reports, explainer, simulations, command-center, payroll contract, locks, transfers.
 * Included by routes/leave.php inside Route::prefix('v1/leave')->middleware('module:leave'). Controllers live in App\Http\Controllers\Leave\Operations.
 * Register routes here only; add per-route middleware('permission:leave....'). Do not edit the other routes/leave/*.php files.
 * Documented in docs/api/leave-operations.md.
 */

// ---- locks (maker-checker unlock) -----------------------------------------------------------------------
Route::get('settings', [SettingsController::class, 'show'])->middleware('permission:leave.manage|leave.policy.manage');
Route::put('settings', [SettingsController::class, 'update'])->middleware('permission:leave.manage|leave.policy.manage');

Route::get('locks', [LockController::class, 'index'])->middleware('permission:leave.lock|leave.unlock|leave.manage');
Route::post('locks/preview', [LockController::class, 'preview'])->middleware('permission:leave.lock');
Route::post('locks', [LockController::class, 'store'])->middleware('permission:leave.lock');
Route::post('locks/{uuid}/unlock-request', [LockController::class, 'unlockRequest'])->middleware('permission:leave.lock|leave.unlock');
Route::post('locks/{uuid}/unlock-approve', [LockController::class, 'unlockApprove'])->middleware('permission:leave.unlock');
Route::post('locks/{uuid}/unlock-reject', [LockController::class, 'unlockReject'])->middleware('permission:leave.unlock');

// ---- payroll contract (units + references + cutoff metadata only) ---------------------------------------
Route::get('payroll/cutoff', [PayrollController::class, 'cutoff'])->middleware('permission:leave.manage|leave.reports.view');
Route::get('payroll/contract', [PayrollController::class, 'contract'])->middleware('permission:leave.manage|leave.reports.view');
Route::post('payroll/contract/batches', [PayrollController::class, 'publish'])->middleware('permission:leave.manage');
Route::get('payroll/contract/batches/{key}', [PayrollController::class, 'batch'])->middleware('permission:leave.manage|leave.reports.view');

// ---- transfers / separation / rehire + leave type mappings ----------------------------------------------
Route::get('transfers', [TransferController::class, 'index'])->middleware('permission:leave.manage');
Route::post('transfers/preview', [TransferController::class, 'preview'])->middleware('permission:leave.manage');
Route::get('transfers/{uuid}', [TransferController::class, 'show'])->middleware('permission:leave.manage');
Route::post('transfers/{uuid}/apply', [TransferController::class, 'apply'])->middleware('permission:leave.manage');
Route::post('transfers/{uuid}/cancel', [TransferController::class, 'cancel'])->middleware('permission:leave.manage');
Route::get('type-mappings', [TransferController::class, 'mappings'])->middleware('permission:leave.manage|leave.policy.manage');
Route::post('type-mappings', [TransferController::class, 'mappingStore'])->middleware('permission:leave.policy.manage');
Route::delete('type-mappings/{id}', [TransferController::class, 'mappingDestroy'])->whereNumber('id')->middleware('permission:leave.policy.manage');

// ---- explainer, simulations, forecast, confidence, story, command center -------------------------------
Route::get('explain/balance', [InsightController::class, 'explainBalance'])->middleware('permission:leave.view|leave.team.view|leave.manage');
Route::get('explain/requests/{uuid}', [InsightController::class, 'explainRequest'])->middleware('permission:leave.view|leave.team.view|leave.manage');
Route::post('simulations/sandbox', [InsightController::class, 'sandbox'])->middleware('permission:leave.simulate');
Route::post('simulations/entitlement', [InsightController::class, 'entitlement'])->middleware('permission:leave.simulate');
Route::post('simulations/impact', [InsightController::class, 'impact'])->middleware('permission:leave.simulate|leave.request');
Route::post('simulations/policy-diff', [InsightController::class, 'diff'])->middleware('permission:leave.simulate|leave.policy.manage');
Route::get('simulations', [InsightController::class, 'simulations'])->middleware('permission:leave.simulate');
Route::get('simulations/{uuid}', [InsightController::class, 'simulation'])->middleware('permission:leave.simulate');
Route::get('forecast', [InsightController::class, 'forecast'])->middleware('permission:leave.view|leave.team.view|leave.manage');
Route::get('confidence', [InsightController::class, 'confidence'])->middleware('permission:leave.view|leave.team.view|leave.manage');
Route::get('time-story', [InsightController::class, 'timeStory'])->middleware('permission:leave.view|leave.team.view|leave.manage');
Route::get('command-center', [InsightController::class, 'commandCenter'])->middleware('permission:leave.reports.view|leave.manage|leave.team.view');

// ---- reports, exports, bulk actions ---------------------------------------------------------------------
Route::get('reports', [ReportController::class, 'catalogue'])->middleware('permission:leave.reports.view|leave.manage');
Route::get('reports/{report}', [ReportController::class, 'report'])->middleware('permission:leave.reports.view|leave.manage');
Route::post('reports/{report}/export', [ReportController::class, 'export'])->middleware('permission:leave.export');
Route::get('exports', [ReportController::class, 'exports'])->middleware('permission:leave.export');
Route::get('exports/{uuid}/download', [ReportController::class, 'download'])->middleware('permission:leave.export');
Route::get('bulk-actions', [ReportController::class, 'bulkActions'])->middleware('permission:leave.manage');
Route::post('bulk-actions/{key}/preview', [ReportController::class, 'bulkPreview'])->middleware('permission:leave.manage');
Route::post('bulk-actions/{key}/run', [ReportController::class, 'bulkRun'])->middleware('permission:leave.manage');
