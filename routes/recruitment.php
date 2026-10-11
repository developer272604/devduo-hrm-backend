<?php

use App\Http\Controllers\Recruitment\RecruitmentController as Recruitment;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/recruitment')->middleware('module:recruitment')->group(function () {
    foreach (['jobs', 'requisitions', 'candidates', 'applications'] as $resource) Route::get($resource, [Recruitment::class, $resource])->middleware('permission:recruitment.view');
    Route::get('users', [Recruitment::class, 'users'])->middleware('permission:recruitment.manage');
    Route::post('jobs', [Recruitment::class, 'createJob'])->middleware('permission:recruitment.manage');
    Route::post('requisitions', [Recruitment::class, 'createRequisition'])->middleware('permission:recruitment.manage');
    Route::post('requisitions/{uuid}/decision', [Recruitment::class, 'decide'])->middleware('permission:recruitment.approve');
    Route::post('candidates', [Recruitment::class, 'createCandidate'])->middleware('permission:recruitment.manage');
    Route::post('applications', [Recruitment::class, 'apply'])->middleware('permission:recruitment.manage');
    Route::post('applications/{uuid}/transition', [Recruitment::class, 'transition'])->middleware('permission:recruitment.manage');
    Route::get('applications/{uuid}/history', [Recruitment::class, 'history'])->middleware('permission:recruitment.view');
    require __DIR__.'/recruitment/core.php';
    require __DIR__.'/recruitment/documents.php';
    require __DIR__.'/recruitment/pipelines.php';
});
