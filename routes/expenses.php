<?php
use App\Http\Controllers\Expenses\ExpenseController as Expenses;
use App\Http\Controllers\Expenses\ExpenseRulesController as Rules;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/expenses')->middleware('module:expenses')->group(function () {
    Route::get('policies', [Expenses::class, 'policies'])->middleware('permission:expenses.self|expenses.manage|expenses.approve');
    Route::post('policies', [Expenses::class, 'createPolicy'])->middleware('permission:expenses.manage');
    Route::get('rules', [Rules::class, 'limits'])->middleware('permission:expenses.self|expenses.manage|expenses.approve');
    Route::post('rules', [Rules::class, 'createLimit'])->middleware('permission:expenses.manage');
    Route::get('rates', [Rules::class, 'rates'])->middleware('permission:expenses.self|expenses.manage|expenses.approve');
    Route::post('rates', [Rules::class, 'createRate'])->middleware('permission:expenses.manage');
    Route::get('fx-rates', [Rules::class, 'fxRates'])->middleware('permission:expenses.fx.manage|expenses.fx.approve|expenses.manage');
    Route::post('fx-rates', [Rules::class, 'createFx'])->middleware('permission:expenses.fx.manage');
    Route::post('fx-rates/overrides', [Rules::class, 'requestOverride'])->middleware('permission:expenses.fx.manage');
    Route::post('fx-rates/{uuid}/decision', [Rules::class, 'decideFx'])->middleware('permission:expenses.fx.approve');
    Route::get('claims', [Expenses::class, 'index'])->middleware('permission:expenses.self|expenses.approve|expenses.manage|expenses.payments.confirm');
    Route::post('claims', [Expenses::class, 'create'])->middleware('permission:expenses.self');
    Route::get('claims/{uuid}', [Expenses::class, 'show'])->middleware('permission:expenses.self|expenses.approve|expenses.manage|expenses.payments.confirm');
    Route::put('claims/{uuid}', [Expenses::class, 'update'])->middleware('permission:expenses.self');
    Route::post('claims/{uuid}/actions', [Expenses::class, 'action'])->middleware('permission:expenses.self|expenses.approve|expenses.payments.confirm');
    Route::post('claims/{uuid}/receipts', [Expenses::class, 'upload'])->middleware('permission:expenses.self');
    Route::get('claims/{uuid}/receipts/{receipt}', [Expenses::class, 'receipt'])->middleware('permission:expenses.self|expenses.approve|expenses.manage|expenses.payments.confirm');
});
// Reports span assets, expenses and travel; each report enforces its own module, permission and scope.
Route::prefix('v1/operations/reports')->group(function () {
    Route::get('{report}', [\App\Http\Controllers\Operations\ReportController::class, 'show'])->middleware('permission:operations.reports.view');
    Route::get('{report}/export', [\App\Http\Controllers\Operations\ReportController::class, 'export'])->middleware('permission:operations.reports.export');
});
