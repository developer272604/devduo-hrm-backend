<?php

use App\Http\Controllers\Intelligence\CenterController;
use App\Http\Controllers\Intelligence\FindingsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/intelligence')->middleware('module:intelligence')->group(function () {
    // Live (not stored) pass-through of the Action Center, kept for compatibility.
    Route::get('findings', FindingsController::class)->middleware('permission:intelligence.view');

    Route::prefix('center')->middleware('permission:intelligence.view')->group(function () {
        Route::get('/', [CenterController::class, 'index']);
        Route::post('scan', [CenterController::class, 'scan'])->middleware('permission:intelligence.manage');
        Route::post('bulk-acknowledge', [CenterController::class, 'bulkAcknowledge'])->middleware('permission:intelligence.manage');
        Route::get('{uuid}', [CenterController::class, 'show'])->whereUuid('uuid');
        Route::get('{uuid}/explain', [CenterController::class, 'explain'])->whereUuid('uuid');
        Route::post('{uuid}/status', [CenterController::class, 'status'])->whereUuid('uuid')->middleware('permission:intelligence.manage');
        Route::post('{uuid}/due', [CenterController::class, 'due'])->whereUuid('uuid')->middleware('permission:intelligence.manage');
        Route::post('{uuid}/task', [CenterController::class, 'task'])->whereUuid('uuid')->middleware('permission:intelligence.manage');
    });

    Route::get('scenarios', [CenterController::class, 'scenarios'])->middleware('permission:intelligence.scenarios.run');
    Route::post('scenarios', [CenterController::class, 'runScenario'])->middleware('permission:intelligence.scenarios.run');
    Route::get('scenarios/{uuid}', [CenterController::class, 'scenario'])->whereUuid('uuid')->middleware('permission:intelligence.scenarios.run');

    Route::get('risk-signals', [CenterController::class, 'riskSignals'])->middleware('permission:intelligence.risk_signals.view');
    Route::get('settings', [CenterController::class, 'settings'])->middleware('permission:intelligence.view');
    Route::put('settings', [CenterController::class, 'saveSettings'])->middleware('permission:intelligence.settings.manage');
    Route::get('employees/{uuid}/time-story', [CenterController::class, 'timeStory'])->whereUuid('uuid')->middleware('permission:intelligence.view');
});
