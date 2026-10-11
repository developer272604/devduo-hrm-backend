<?php
use App\Http\Controllers\Expenses\ExpenseController as Expenses;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/expenses')->middleware('module:expenses')->group(function () {
    Route::get('policies', [Expenses::class, 'policies'])->middleware('permission:expenses.self|expenses.manage|expenses.approve');
    Route::post('policies', [Expenses::class, 'createPolicy'])->middleware('permission:expenses.manage');
    Route::get('claims', [Expenses::class, 'index'])->middleware('permission:expenses.self|expenses.approve|expenses.manage|expenses.payments.confirm');
    Route::post('claims', [Expenses::class, 'create'])->middleware('permission:expenses.self');
    Route::get('claims/{uuid}', [Expenses::class, 'show'])->middleware('permission:expenses.self|expenses.approve|expenses.manage|expenses.payments.confirm');
    Route::post('claims/{uuid}/actions', [Expenses::class, 'action'])->middleware('permission:expenses.self|expenses.approve|expenses.payments.confirm');
    Route::post('claims/{uuid}/receipts', [Expenses::class, 'upload'])->middleware('permission:expenses.self');
    Route::get('claims/{uuid}/receipts/{receipt}', [Expenses::class, 'receipt'])->middleware('permission:expenses.self|expenses.approve|expenses.manage|expenses.payments.confirm');
});
