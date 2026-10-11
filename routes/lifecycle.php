<?php

use App\Http\Controllers\Lifecycle\LifecycleController as L;
use Illuminate\Support\Facades\Route;

/* Phase 7 core. Contract: docs/api/lifecycle-onboarding.md. Required from routes/web.php inside the authenticated tenant group. */
Route::prefix('v1/lifecycle')->middleware('module:lifecycle')->group(function () {
    Route::get('templates', [L::class, 'templates'])->middleware('permission:lifecycle.templates.manage|lifecycle.view');
    Route::post('templates', [L::class, 'createTemplate'])->middleware('permission:lifecycle.templates.manage');
    Route::get('templates/{uuid}', [L::class, 'showTemplate'])->middleware('permission:lifecycle.templates.manage|lifecycle.view');
    Route::post('templates/{uuid}/versions', [L::class, 'addVersion'])->middleware('permission:lifecycle.templates.manage');
    Route::post('template-versions/{uuid}/publish', [L::class, 'publishVersion'])->middleware('permission:lifecycle.templates.manage');

    Route::get('cases', [L::class, 'cases'])->middleware('permission:lifecycle.view');
    Route::post('cases', [L::class, 'createCase'])->middleware('permission:lifecycle.manage');
    Route::get('cases/{uuid}', [L::class, 'showCase'])->middleware('permission:lifecycle.view');
    Route::post('cases/{uuid}/tasks', [L::class, 'addTask'])->middleware('permission:lifecycle.manage');
    Route::post('cases/{uuid}/reschedule', [L::class, 'reschedule'])->middleware('permission:lifecycle.manage');
    Route::post('cases/{uuid}/cancel', [L::class, 'cancelCase'])->middleware('permission:lifecycle.manage');
    Route::get('cases/{uuid}/clearance', [L::class, 'clearance'])->middleware('permission:lifecycle.view');
    Route::post('cases/{uuid}/settlement', [L::class, 'settlement'])->middleware('permission:lifecycle.clearance.manage');
    Route::get('cases/{uuid}/exit-interview', [L::class, 'exitInterview'])->middleware('permission:lifecycle.exit.view');

    Route::get('me/tasks', [L::class, 'myTasks'])->middleware('permission:lifecycle.self');
    Route::get('me/exit-interview', [L::class, 'myExitInterview'])->middleware('permission:lifecycle.self');
    Route::put('me/exit-interview', [L::class, 'submitMyExitInterview'])->middleware('permission:lifecycle.self');
    Route::post('tasks/{uuid}/complete', [L::class, 'completeTask'])->middleware('permission:lifecycle.self|lifecycle.manage|lifecycle.clearance.manage');

    Route::get('dashboard', [L::class, 'dashboard'])->middleware('permission:lifecycle.view');
    Route::get('preboarders', [L::class, 'preboarders'])->middleware('permission:lifecycle.view');

    Route::get('moves', [L::class, 'moves'])->middleware('permission:lifecycle.moves.manage|lifecycle.moves.approve');
    Route::post('moves', [L::class, 'requestMove'])->middleware('permission:lifecycle.moves.manage');
    Route::post('moves/{uuid}/decision', [L::class, 'decideMove'])->middleware('permission:lifecycle.moves.approve');
    Route::post('moves/{uuid}/cancel', [L::class, 'cancelMove'])->middleware('permission:lifecycle.moves.manage|lifecycle.moves.approve');

    Route::get('alumni', [L::class, 'alumni'])->middleware('permission:lifecycle.alumni.manage');
    Route::put('alumni/{uuid}/eligibility', [L::class, 'setEligibility'])->middleware('permission:lifecycle.alumni.manage');
});
