<?php

use App\Http\Controllers\Leave\Policies\ConfigController;
use App\Http\Controllers\Leave\Policies\CycleController;
use App\Http\Controllers\Leave\Policies\PolicyController;
use App\Http\Controllers\Leave\Policies\TypeController;
use Illuminate\Support\Facades\Route;

/*
 * Leave workstream A - types, policies, versions, assignments, cycles, eligibility, restriction/document rules, optional holiday pools.
 * Included by routes/leave.php inside Route::prefix('v1/leave')->middleware('module:leave'). Controllers live in App\Http\Controllers\Leave\Policies.
 */
$manage = 'permission:leave.policy.manage|leave.manage';
$typesRead = 'permission:leave.view|leave.request|leave.policy.manage|leave.manage';
$resolveRead = 'permission:leave.policy.manage|leave.manage|leave.view';

// Leave types
Route::get('types', [TypeController::class, 'index'])->middleware($typesRead);
Route::get('types/meta', [TypeController::class, 'meta'])->middleware($typesRead);
Route::post('types', [TypeController::class, 'store'])->middleware($manage);
Route::post('types/seed-defaults', [TypeController::class, 'seedDefaults'])->middleware($manage);
Route::get('types/{uuid}', [TypeController::class, 'show'])->middleware($typesRead);
Route::patch('types/{uuid}', [TypeController::class, 'update'])->middleware($manage);
Route::delete('types/{uuid}', [TypeController::class, 'destroy'])->middleware($manage);

// Policies, versions, assignments
Route::get('policies', [PolicyController::class, 'index'])->middleware($manage);
Route::post('policies', [PolicyController::class, 'store'])->middleware($manage);
Route::get('policies/resolve', [PolicyController::class, 'resolve'])->middleware($resolveRead);
Route::get('policies/{uuid}', [PolicyController::class, 'show'])->middleware($manage);
Route::patch('policies/{uuid}', [PolicyController::class, 'update'])->middleware($manage);
Route::get('policies/{uuid}/diff', [PolicyController::class, 'diff'])->middleware($manage);
Route::post('policies/{uuid}/versions', [PolicyController::class, 'newVersion'])->middleware($manage);
Route::get('policies/{uuid}/versions/{version}', [PolicyController::class, 'showVersion'])->middleware($manage)->whereNumber('version');
Route::patch('policies/{uuid}/versions/{version}', [PolicyController::class, 'updateVersion'])->middleware($manage)->whereNumber('version');
Route::delete('policies/{uuid}/versions/{version}', [PolicyController::class, 'destroyVersion'])->middleware($manage)->whereNumber('version');
Route::put('policies/{uuid}/versions/{version}/type-rules/{type}', [PolicyController::class, 'putTypeRule'])->middleware($manage)->whereNumber('version');
Route::delete('policies/{uuid}/versions/{version}/type-rules/{type}', [PolicyController::class, 'deleteTypeRule'])->middleware($manage)->whereNumber('version');
Route::post('policies/{uuid}/versions/{version}/publish', [PolicyController::class, 'publish'])->middleware($manage)->whereNumber('version');
Route::post('policies/{uuid}/versions/{version}/schedule', [PolicyController::class, 'schedule'])->middleware($manage)->whereNumber('version');
Route::post('policies/{uuid}/versions/{version}/retire', [PolicyController::class, 'retire'])->middleware($manage)->whereNumber('version');
Route::get('policy-assignments', [PolicyController::class, 'assignments'])->middleware($manage);
Route::post('policy-assignments', [PolicyController::class, 'storeAssignment'])->middleware($manage);
Route::patch('policy-assignments/{uuid}', [PolicyController::class, 'updateAssignment'])->middleware($manage);
Route::delete('policy-assignments/{uuid}', [PolicyController::class, 'destroyAssignment'])->middleware($manage);

// Cycles
Route::get('cycles', [CycleController::class, 'index'])->middleware($manage);
Route::post('cycles', [CycleController::class, 'store'])->middleware($manage);
Route::get('cycles/{uuid}', [CycleController::class, 'show'])->middleware($manage);
Route::patch('cycles/{uuid}', [CycleController::class, 'update'])->middleware($manage);
Route::get('cycles/{uuid}/periods', [CycleController::class, 'periods'])->middleware($manage);
Route::post('cycles/{uuid}/periods/generate', [CycleController::class, 'generate'])->middleware($manage);
Route::get('cycles/{uuid}/period-for', [CycleController::class, 'periodFor'])->middleware($resolveRead);

// Eligibility, restriction rules, document rules, optional holiday pools
Route::get('eligibility/check', [ConfigController::class, 'eligibility'])->middleware($resolveRead);
Route::get('restriction-rules', [ConfigController::class, 'restrictions'])->middleware($manage);
Route::post('restriction-rules', [ConfigController::class, 'storeRestriction'])->middleware($manage);
Route::patch('restriction-rules/{uuid}', [ConfigController::class, 'updateRestriction'])->middleware($manage);
Route::delete('restriction-rules/{uuid}', [ConfigController::class, 'destroyRestriction'])->middleware($manage);
Route::get('document-rules', [ConfigController::class, 'documentRulesIndex'])->middleware($manage);
Route::post('document-rules', [ConfigController::class, 'storeDocumentRule'])->middleware($manage);
Route::patch('document-rules/{id}', [ConfigController::class, 'updateDocumentRule'])->middleware($manage)->whereNumber('id');
Route::delete('document-rules/{id}', [ConfigController::class, 'destroyDocumentRule'])->middleware($manage)->whereNumber('id');
Route::get('optional-holiday-pools', [ConfigController::class, 'pools'])->middleware($manage);
Route::post('optional-holiday-pools', [ConfigController::class, 'storePool'])->middleware($manage);
Route::patch('optional-holiday-pools/{uuid}', [ConfigController::class, 'updatePool'])->middleware($manage);
Route::delete('optional-holiday-pools/{uuid}', [ConfigController::class, 'destroyPool'])->middleware($manage);
