<?php

use App\Http\Controllers\Learning\LearningController as Learning;
use Illuminate\Support\Facades\Route;

/* Phase 9 lean core. Contract: docs/api/learning.md. Included in authenticated tenant routes. */
Route::prefix('v1/learning')->middleware('module:learning')->group(function () {
    Route::get('courses', [Learning::class, 'courses'])->middleware('permission:learning.self|learning.view|learning.catalog.manage');
    Route::get('courses/{uuid}', [Learning::class, 'course'])->middleware('permission:learning.self|learning.view|learning.catalog.manage');
    Route::post('courses', [Learning::class, 'createCourse'])->middleware('permission:learning.catalog.manage');
    Route::patch('courses/{uuid}', [Learning::class, 'updateCourse'])->middleware('permission:learning.catalog.manage');
    Route::post('courses/{uuid}/status', [Learning::class, 'courseStatus'])->middleware('permission:learning.catalog.manage');
    Route::get('courses/{uuid}/sessions', [Learning::class, 'sessions'])->middleware('permission:learning.self|learning.view|learning.catalog.manage');
    Route::post('courses/{uuid}/sessions', [Learning::class, 'createSession'])->middleware('permission:learning.catalog.manage');
    Route::post('courses/{uuid}/enrol', [Learning::class, 'enrol'])->middleware('permission:learning.self');
    Route::get('my', [Learning::class, 'myLearning'])->middleware('permission:learning.self');
    Route::get('paths', [Learning::class, 'paths'])->middleware('permission:learning.self|learning.view|learning.catalog.manage');
    Route::post('paths', [Learning::class, 'createPath'])->middleware('permission:learning.catalog.manage');
    Route::get('skills', [Learning::class, 'skills'])->middleware('permission:learning.self|learning.view|learning.skills.manage');
    Route::get('skill-levels', [Learning::class, 'levels'])->middleware('permission:learning.self|learning.view|learning.skills.manage');
    Route::get('my/skills', [Learning::class, 'mySkills'])->middleware('permission:learning.self');
    Route::post('my/skills', [Learning::class, 'declareSkill'])->middleware('permission:learning.self');
});
