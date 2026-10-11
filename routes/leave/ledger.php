<?php

/*
 * Leave workstream B - me/balances, ledger, accounts, accrual-runs, carry-forward, expiry, adjustments, imports.
 * Included by routes/leave.php inside Route::prefix('v1/leave')->middleware('module:leave'). Controllers live in App\Http\Controllers\Leave\Ledger.
 * Register routes here only; add per-route middleware('permission:leave....'). Do not edit the other routes/leave/*.php files.
 */

use App\Http\Controllers\Leave\Ledger\AccrualController;
use App\Http\Controllers\Leave\Ledger\AdjustmentController;
use App\Http\Controllers\Leave\Ledger\BalanceController;
use App\Http\Controllers\Leave\Ledger\ImportController;
use Illuminate\Support\Facades\Route;

$read = 'permission:leave.view|leave.team.view|leave.manage|leave.adjust|leave.reports.view';

// Balances and ledger (traceable, data-scoped)
Route::get('me/balances', [BalanceController::class, 'me'])->middleware('permission:leave.view');
Route::get('me/expiries', [BalanceController::class, 'myExpiries'])->middleware('permission:leave.view');
Route::get('team/balances', [BalanceController::class, 'team'])->middleware('permission:leave.team.view|leave.manage|leave.reports.view');
Route::get('employees/{employee}/balances', [BalanceController::class, 'employee'])->middleware($read);
Route::get('accounts/{account}', [BalanceController::class, 'account'])->middleware($read);
Route::get('accounts/{account}/ledger', [BalanceController::class, 'ledger'])->middleware($read);
Route::get('ledger/entries/{entry}', [BalanceController::class, 'entry'])->middleware($read);
Route::post('accounts', [BalanceController::class, 'open'])->middleware('permission:leave.manage');
Route::post('entitlements/calculate', [BalanceController::class, 'calculate'])->middleware('permission:leave.accrual.run|leave.manage');
Route::get('expiries', [BalanceController::class, 'expiries'])->middleware('permission:leave.manage|leave.team.view|leave.accrual.run');

// Accrual runs, carry-forward, expiry
Route::middleware('permission:leave.accrual.run')->group(function () {
    Route::get('accrual-runs', [AccrualController::class, 'index']);
    Route::post('accrual-runs/preview', [AccrualController::class, 'preview']);
    Route::post('accrual-runs', [AccrualController::class, 'store'])->middleware('throttle:20,1');
    Route::get('accrual-runs/{run}', [AccrualController::class, 'show']);
    Route::get('accrual-runs/{run}/items', [AccrualController::class, 'items']);
    Route::get('accrual-runs/{run}/errors', [AccrualController::class, 'errors']);
    Route::post('accrual-runs/{run}/execute', [AccrualController::class, 'execute']);
    Route::post('accrual-runs/{run}/retry', [AccrualController::class, 'retry']);
    Route::get('carry-forward', [AccrualController::class, 'carryIndex']);
    Route::post('carry-forward/preview', [AccrualController::class, 'carryPreview']);
    Route::post('carry-forward/run', [AccrualController::class, 'carryRun']);
    Route::post('expiries/process', [AccrualController::class, 'expiryProcess']);
});

// Manual adjustments (maker-checker) and bulk grants / debits
Route::get('adjustments', [AdjustmentController::class, 'index'])->middleware('permission:leave.adjust|leave.adjust.approve');
Route::post('adjustments', [AdjustmentController::class, 'store'])->middleware('permission:leave.adjust');
Route::post('adjustments/bulk/preview', [AdjustmentController::class, 'bulkPreview'])->middleware('permission:leave.adjust');
Route::post('adjustments/bulk', [AdjustmentController::class, 'bulkStore'])->middleware('permission:leave.adjust');
Route::post('adjustments/bulk/{bulkKey}/approve', [AdjustmentController::class, 'bulkApprove'])->middleware('permission:leave.adjust.approve');
Route::post('adjustments/bulk/{bulkKey}/reject', [AdjustmentController::class, 'bulkReject'])->middleware('permission:leave.adjust.approve');
Route::post('adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve'])->middleware('permission:leave.adjust.approve');
Route::post('adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject'])->middleware('permission:leave.adjust.approve');

// Imports
Route::middleware('permission:leave.import')->group(function () {
    Route::get('imports/template', [ImportController::class, 'template']);
    Route::get('imports', [ImportController::class, 'index']);
    Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:20,1');
    Route::get('imports/{uuid}', [ImportController::class, 'show']);
    Route::put('imports/{uuid}/mapping', [ImportController::class, 'mapping']);
    Route::post('imports/{uuid}/validate', [ImportController::class, 'validateImport']);
    Route::get('imports/{uuid}/rows', [ImportController::class, 'rows']);
    Route::post('imports/{uuid}/commit', [ImportController::class, 'commit']);
    Route::post('imports/{uuid}/retry', [ImportController::class, 'retry']);
    Route::get('imports/{uuid}/errors', [ImportController::class, 'errors']);
});
