<?php

use App\Http\Controllers\Performance\ConfigController as Config;
use App\Http\Controllers\Performance\GoalController as Goals;
use Illuminate\Support\Facades\Route;

/* Phase 8 core. Contract: docs/api/performance.md. Required from routes/web.php inside the authenticated tenant group. */
Route::prefix('v1/performance')->middleware('module:performance')->group(function () {
    $read = 'permission:performance.self|performance.view';
    // Configuration
    Route::get('rating-scales', [Config::class, 'scales'])->middleware($read);
    Route::post('rating-scales', [Config::class, 'createScale'])->middleware('permission:performance.cycles.manage');
    Route::get('competencies', [Config::class, 'competencies'])->middleware($read);
    Route::post('competencies', [Config::class, 'createCompetency'])->middleware('permission:performance.cycles.manage');
    Route::get('templates', [Config::class, 'templates'])->middleware('permission:performance.cycles.manage');
    Route::post('templates', [Config::class, 'createTemplate'])->middleware('permission:performance.cycles.manage');
    Route::get('cycles', [Config::class, 'cycleList'])->middleware($read);
    Route::post('cycles', [Config::class, 'createCycle'])->middleware('permission:performance.cycles.manage');
    Route::get('cycles/{uuid}', [Config::class, 'cycleShow'])->middleware($read);
    Route::post('cycles/{uuid}/publish', [Config::class, 'publish'])->middleware('permission:performance.cycles.manage');
    Route::get('cycles/{uuid}/eligibility', [Config::class, 'eligibility'])->middleware('permission:performance.cycles.manage');
    Route::post('cycles/{uuid}/launch', [Config::class, 'launch'])->middleware('permission:performance.cycles.manage');
    Route::get('dashboard', [Config::class, 'dashboard'])->middleware('permission:performance.reports.view');
    Route::get('outcomes', [Config::class, 'outcomes'])->middleware('permission:performance.outcomes.view');

    // Goals / OKRs / KPIs
    Route::get('goals', [Goals::class, 'index'])->middleware($read);
    Route::post('goals', [Goals::class, 'store'])->middleware('permission:performance.self|performance.goals.manage');
    Route::get('goals/{uuid}', [Goals::class, 'show'])->middleware($read);
    Route::patch('goals/{uuid}', [Goals::class, 'update'])->middleware('permission:performance.self|performance.goals.manage');
    Route::get('goals/{uuid}/alignment', [Goals::class, 'alignment'])->middleware($read);
    Route::post('goals/{uuid}/submit', [Goals::class, 'submit'])->middleware('permission:performance.self');
    Route::post('goals/{uuid}/decision', [Goals::class, 'decision'])->middleware('permission:performance.goals.approve');
    Route::post('goals/{uuid}/cascade', [Goals::class, 'cascade'])->middleware('permission:performance.goals.manage');
    Route::post('goals/{uuid}/key-results', [Goals::class, 'keyResult'])->middleware('permission:performance.self|performance.goals.manage');
    Route::post('goals/{uuid}/checkins', [Goals::class, 'checkin'])->middleware('permission:performance.self|performance.goals.manage');
    Route::post('goals/{uuid}/complete', [Goals::class, 'complete'])->middleware('permission:performance.self|performance.goals.manage');
    Route::post('goals/{uuid}/cancel', [Goals::class, 'cancel'])->middleware('permission:performance.self|performance.goals.manage');

    require __DIR__.'/performance/reviews.php';
    require __DIR__.'/performance/collaboration.php';
});
