<?php

use App\Http\Controllers\Lifecycle\LifecycleController as L;
use App\Http\Controllers\Lifecycle\ContractRenewalController as C;
use App\Http\Controllers\Lifecycle\ResignationController as R;
use App\Http\Controllers\Lifecycle\ReturnController as W;
use Illuminate\Support\Facades\Route;

/* Phase 7 core. Contract: docs/api/lifecycle-onboarding.md. Required from routes/web.php inside the authenticated tenant group. */
Route::prefix('v1/lifecycle')->middleware('module:lifecycle')->group(function () {
    Route::get('contract-renewals/employees', [C::class, 'employees'])->middleware('permission:people.manage');
    Route::get('contract-renewals', [C::class, 'index'])->middleware('permission:people.manage');
    Route::post('contract-renewals', [C::class, 'store'])->middleware('permission:people.manage');
    Route::post('contract-renewals/{uuid}/decision', [C::class, 'decide'])->middleware('permission:people.manage');
    Route::get('returns/employees', [W::class, 'employees'])->middleware('permission:people.manage');
    Route::get('returns', [W::class, 'index'])->middleware('permission:people.manage');
    Route::post('returns', [W::class, 'store'])->middleware('permission:people.manage');
    Route::post('returns/{uuid}/decision', [W::class, 'decide'])->middleware('permission:people.manage');
    Route::get('me/resignation-employments', [R::class, 'employments'])->middleware('permission:lifecycle.self');
    Route::get('me/resignations', [R::class, 'mine'])->middleware('permission:lifecycle.self');
    Route::post('me/resignations', [R::class, 'store'])->middleware('permission:lifecycle.self');
    Route::post('me/resignations/{uuid}/withdraw', [R::class, 'withdraw'])->middleware('permission:lifecycle.self');
    Route::get('resignations', [R::class, 'index'])->middleware('permission:people.separate');
    Route::post('resignations/{uuid}/decision', [R::class, 'decide'])->middleware('permission:people.separate');
    Route::get('task-owners', [L::class, 'taskOwners'])->middleware('permission:lifecycle.templates.manage');
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

// Organizational Knowledge Continuity.
Route::prefix('v1/lifecycle/continuity')->middleware(['module:lifecycle', 'permission:lifecycle.continuity.view'])->group(function () {
    $C = \App\Http\Controllers\Lifecycle\ContinuityController::class;
    Route::get('responsibilities', [$C, 'index']);
    Route::get('risk', [$C, 'risk']);
    Route::middleware('permission:lifecycle.continuity.manage')->group(function () use ($C) {
        Route::post('responsibilities', [$C, 'store']);
        Route::patch('responsibilities/{uuid}', [$C, 'update'])->whereUuid('uuid');
        Route::post('handover/{uuid}', [$C, 'handover'])->whereUuid('uuid');
    });
});

// Employee Lifecycle Dependency Graph.
Route::prefix('v1/lifecycle')->middleware(['module:lifecycle', 'permission:lifecycle.view'])->group(function () {
    Route::get('employees/{uuid}/dependency-graph', [\App\Http\Controllers\Lifecycle\GraphController::class, 'show'])->whereUuid('uuid');
});
