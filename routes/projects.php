<?php

use App\Http\Controllers\ProjectsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/projects')->middleware('module:work')->group(function () {
    Route::get('/', [ProjectsController::class, 'index'])->middleware('permission:work.view|projects.manage');
    Route::post('/', [ProjectsController::class, 'create'])->middleware('permission:projects.manage');
    Route::get('people', [ProjectsController::class, 'people'])->middleware('permission:projects.manage');
    Route::put('{project}/members', [ProjectsController::class, 'members'])->whereUuid('project')->middleware('permission:projects.manage');
    Route::post('{project}/tasks', [ProjectsController::class, 'task'])->whereUuid('project')->middleware('permission:projects.manage');
    Route::get('timesheet', [ProjectsController::class, 'own'])->middleware('permission:work.view');
    Route::put('timesheet', [ProjectsController::class, 'save'])->middleware('permission:work.view');
    Route::get('reviews', [ProjectsController::class, 'reviews'])->middleware('permission:projects.manage');
    Route::post('reviews/{sheet}', [ProjectsController::class, 'review'])->whereUuid('sheet')->middleware('permission:projects.manage');
    Route::get('report', [ProjectsController::class, 'report'])->middleware('permission:projects.manage');
});
