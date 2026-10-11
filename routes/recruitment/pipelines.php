<?php

use App\Http\Controllers\Recruitment\PipelineController as Pipelines;
use Illuminate\Support\Facades\Route;

/* P06-T11: configurable pipelines/SLAs and requisition versions. Required from routes/recruitment.php inside the v1/recruitment group. */
Route::get('pipelines', [Pipelines::class, 'index'])->middleware('permission:recruitment.view');
Route::get('pipelines/{uuid}', [Pipelines::class, 'show'])->middleware('permission:recruitment.view');
Route::get('pipelines/{uuid}/versions', [Pipelines::class, 'versions'])->middleware('permission:recruitment.view');
Route::post('pipelines', [Pipelines::class, 'store'])->middleware('permission:recruitment.pipeline.manage');
Route::put('pipelines/{uuid}', [Pipelines::class, 'update'])->middleware('permission:recruitment.pipeline.manage');
Route::post('pipelines/{uuid}/versions', [Pipelines::class, 'newVersion'])->middleware('permission:recruitment.pipeline.manage');
Route::post('pipelines/{uuid}/default', [Pipelines::class, 'makeDefault'])->middleware('permission:recruitment.pipeline.manage');
Route::delete('pipelines/{uuid}', [Pipelines::class, 'destroy'])->middleware('permission:recruitment.pipeline.manage');
Route::post('requisitions/{uuid}/amend', [Pipelines::class, 'amend'])->middleware('permission:recruitment.manage');
Route::get('requisitions/{uuid}/versions', [Pipelines::class, 'requisitionVersions'])->middleware('permission:recruitment.view');
Route::post('requisitions/{uuid}/versions/{version}/decision', [Pipelines::class, 'decideVersion'])->whereNumber('version')->middleware('permission:recruitment.approve');
