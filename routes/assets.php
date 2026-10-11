<?php
use App\Http\Controllers\Assets\AssetController;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/assets')->middleware('module:assets')->group(function () {
    Route::get('/', [AssetController::class, 'index'])->middleware('permission:assets.self|assets.manage');
    Route::post('/', [AssetController::class, 'store'])->middleware('permission:assets.manage');
    Route::get('employees', [AssetController::class, 'employees'])->middleware('permission:assets.manage');
    Route::get('{uuid}', [AssetController::class, 'show'])->middleware('permission:assets.self|assets.manage');
    Route::post('{uuid}/actions', [AssetController::class, 'transition'])->middleware('permission:assets.self|assets.manage');
});
