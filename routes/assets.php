<?php
use App\Http\Controllers\Assets\AssetController;
use App\Http\Controllers\Assets\AssetOpsController as Ops;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/assets')->middleware('module:assets')->group(function () {
    Route::get('/', [AssetController::class, 'index'])->middleware('permission:assets.self|assets.manage');
    Route::post('/', [AssetController::class, 'store'])->middleware('permission:assets.manage');
    Route::get('employees', [AssetController::class, 'employees'])->middleware('permission:assets.manage');
    Route::post('imports', [Ops::class, 'previewImport'])->middleware('permission:assets.manage');
    Route::get('imports/{uuid}', [Ops::class, 'showPreview'])->middleware('permission:assets.manage');
    Route::post('imports/{uuid}/commit', [Ops::class, 'commitImport'])->middleware('permission:assets.manage');
    Route::post('bulk/preview', [Ops::class, 'previewBulk'])->middleware('permission:assets.manage');
    Route::post('bulk/{uuid}/commit', [Ops::class, 'commitBulk'])->middleware('permission:assets.manage');
    Route::get('reports/depreciation', [Ops::class, 'depreciation'])->middleware('permission:assets.manage');
    Route::get('{uuid}', [AssetController::class, 'show'])->middleware('permission:assets.self|assets.manage');
    Route::get('{uuid}/history', [Ops::class, 'history'])->middleware('permission:assets.manage');
    Route::post('{uuid}/maintenance', [Ops::class, 'maintenance'])->middleware('permission:assets.manage');
    Route::post('{uuid}/procurement', [Ops::class, 'procurement'])->middleware('permission:assets.manage');
    Route::post('{uuid}/actions', [AssetController::class, 'transition'])->middleware('permission:assets.self|assets.manage');
});
