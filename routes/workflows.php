<?php

use App\Http\Controllers\Workflow\GenerationController as G;
use App\Http\Controllers\Workflow\WorkflowController as W;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/workflows')->middleware('module:workflows')->group(function () {
    Route::get('definitions', [W::class, 'definitions'])->middleware('permission:workflows.manage');
    Route::post('definitions', [W::class, 'save'])->middleware('permission:workflows.manage');
    Route::get('definitions/{uuid}', [W::class, 'definition'])->middleware('permission:workflows.manage');
    Route::put('definitions/{uuid}', [W::class, 'save'])->middleware('permission:workflows.manage');
    Route::post('definitions/{uuid}/publish', [W::class, 'publish'])->middleware('permission:workflows.publish');
    Route::get('options', [W::class, 'options'])->middleware('permission:workflows.manage');
    Route::get('deliveries', [W::class, 'deliveries'])->middleware('permission:workflows.manage');
    Route::post('deliveries/{id}/retry', [W::class, 'retryDelivery'])->middleware('permission:workflows.manage');
    Route::post('simulate', [W::class, 'simulate'])->middleware('permission:workflows.manage');
    Route::post('generate', [G::class, 'generate'])->middleware(['permission:workflows.manage', 'throttle:30,1']);
    Route::get('generations', [G::class, 'index'])->middleware('permission:workflows.manage');
    Route::post('generations/{uuid}/draft', [G::class, 'draft'])->whereUuid('uuid')->middleware('permission:workflows.manage');
    Route::post('instances', [W::class, 'start'])->middleware('permission:workflows.run');
    Route::get('instances', [W::class, 'instances'])->middleware('permission:workflows.manage|workflows.act|workflows.run');
    Route::get('instances/{uuid}', [W::class, 'show'])->middleware('permission:workflows.manage|workflows.act|workflows.run');
    Route::get('instances/{uuid}/delegates', [W::class, 'delegationOptions'])->middleware('permission:workflows.manage|workflows.act');
    Route::post('instances/{uuid}/delegate', [W::class, 'delegate'])->middleware('permission:workflows.manage|workflows.act');
    Route::post('instances/{uuid}/decide', [W::class, 'decide'])->middleware('permission:workflows.act');
    Route::post('instances/{uuid}/control', [W::class, 'control'])->middleware('permission:workflows.manage');
});
